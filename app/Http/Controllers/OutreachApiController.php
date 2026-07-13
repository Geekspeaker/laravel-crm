<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * Token-gated write API for the outreach agent (/api/outreach/*).
 *
 * Two endpoints:
 *   POST /api/outreach/leads/upsert  — create/update Org+Person+Lead (idempotent by email)
 *   POST /api/outreach/touches       — append an activity + optionally set the stage
 *
 * The upsert mirrors `skimify:import-leads` (same repositories + field mapping) so
 * behaviour matches the bulk importer.
 */
class OutreachApiController extends Controller
{
    /** Custom attribute codes that live on the Lead. */
    private array $leadAttributeFields = [
        'fit_score', 'fund_stage', 'check_size', 'thesis_fit', 'affiliate_network',
        'category', 'payout_model', 'company_size', 'industry', 'region', 'priority',
    ];

    /** Custom attribute codes that live on the Person. */
    private array $personAttributeFields = ['linkedin', 'x_handle', 'website', 'alt_emails'];

    /** Valid Krayin pipeline stage codes (default pipeline). */
    private array $validStages = ['new', 'follow-up', 'prospect', 'negotiation', 'won', 'lost'];

    public function upsertLead(Request $request, PersonRepository $personRepository, LeadRepository $leadRepository)
    {
        $data = $request->all();

        $email = strtolower(trim($data['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'invalid_email', 'message' => 'A valid "email" is required.'], 422);
        }

        $ownerId = DB::table('users')->orderBy('id')->value('id');
        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: ($data['name'] ?? $email);
        $company = trim($data['company'] ?? '');
        $title = trim($data['title'] ?? '');

        $sourceId = $this->resolveSource($data['source'] ?? 'Direct');
        $pipelineId = 1;
        $stageCode = in_array($data['stage'] ?? '', $this->validStages, true) ? $data['stage'] : 'new';
        $stageId = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->where('code', $stageCode)->value('id') ?? 1;

        // ---- Person (create or update) ----
        $personAttrs = [];
        foreach ($this->personAttributeFields as $f) {
            if (! empty($data[$f])) {
                $personAttrs[$f] = $data[$f];
            }
        }

        $person = Person::whereJsonContains('emails', [['value' => $email]])->first();

        if (! $person) {
            $person = $personRepository->create(array_filter(array_merge([
                'name' => $name,
                'emails' => [['value' => $email, 'label' => 'work']],
                'job_title' => $title ?: null,
                'organization_name' => $company ?: null,
                'user_id' => $ownerId,
                'entity_type' => 'persons',
            ], $personAttrs), fn ($v) => ! is_null($v)));
        } elseif ($personAttrs || $title) {
            $update = array_merge($personAttrs, [
                'entity_type' => 'persons',
                'emails' => [['value' => $email, 'label' => 'work']],
                'user_id' => $person->user_id,
            ]);
            $codes = array_keys($personAttrs);

            if ($title && empty($person->job_title)) {
                $update['job_title'] = $title;
                $codes[] = 'job_title';
            }

            if ($codes) {
                $personRepository->update($update, $person->id, $codes);
            }
        }

        if (is_null($person->contact_numbers)) {
            $person->contact_numbers = [];
            $person->save();
        }

        // ---- Lead (create or update) ----
        $lead = Lead::where('person_id', $person->id)->first();
        $created = ! $lead;

        $leadAttrs = [];
        foreach ($this->leadAttributeFields as $f) {
            if (! empty($data[$f])) {
                $leadAttrs[$f] = $data[$f];
            }
        }

        if (! $lead) {
            $lead = $leadRepository->create(array_merge([
                'title' => $company ? "{$name} - {$company}" : $name,
                'lead_value' => 0,
                'status' => 1,
                'person_id' => $person->id,
                'lead_source_id' => $sourceId,
                'lead_type_id' => 1,
                'lead_pipeline_id' => $pipelineId,
                'lead_pipeline_stage_id' => $stageId,
                'user_id' => $ownerId,
                'entity_type' => 'leads',
            ], $leadAttrs));
        } else {
            $update = array_merge($leadAttrs, ['entity_type' => 'leads']);
            $codes = array_keys($leadAttrs);

            // Only move the stage forward if the caller explicitly passed one.
            if (! empty($data['stage']) && in_array($data['stage'], $this->validStages, true)) {
                $update['lead_pipeline_stage_id'] = $stageId;
            }

            if ($codes || isset($update['lead_pipeline_stage_id'])) {
                $leadRepository->update($update, $lead->id, $codes);
            }
        }

        return response()->json([
            'action' => $created ? 'created' : 'updated',
            'lead_id' => $lead->id,
            'person_id' => $person->id,
            'stage' => $stageCode,
        ]);
    }

    public function logTouch(Request $request, ActivityRepository $activityRepository, LeadRepository $leadRepository)
    {
        $data = $request->all();

        // Resolve the lead by id or by email.
        $lead = null;
        if (! empty($data['lead_id'])) {
            $lead = Lead::find($data['lead_id']);
        } elseif (! empty($data['email'])) {
            $person = Person::whereJsonContains('emails', [['value' => strtolower(trim($data['email']))]])->first();
            $lead = $person ? Lead::where('person_id', $person->id)->first() : null;
        }

        if (! $lead) {
            return response()->json(['error' => 'lead_not_found', 'message' => 'No lead matched lead_id/email.'], 404);
        }

        $channel = strtolower(trim($data['channel'] ?? 'note'));
        $direction = strtolower(trim($data['direction'] ?? 'outbound'));
        $subject = trim($data['subject'] ?? ucfirst($channel).' touch');
        $note = trim($data['note'] ?? '');
        $occurredAt = ! empty($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        // Map channel → Krayin activity type (call/meeting exist; others become notes).
        $type = in_array($channel, ['call', 'meeting', 'lunch'], true) ? $channel : 'note';

        // Idempotency: skip if the same touch was already logged on this lead.
        $dedupe = md5(implode('|', [$lead->id, $channel, $direction, $subject, $occurredAt->toDateTimeString()]));

        $exists = $activityRepository->getModel()->newQuery()
            ->whereHas('leads', fn ($q) => $q->where('leads.id', $lead->id))
            ->where('additional', 'like', '%'.$dedupe.'%')
            ->exists();

        if ($exists) {
            return response()->json(['action' => 'duplicate_ignored', 'lead_id' => $lead->id]);
        }

        $ownerId = $lead->user_id ?: DB::table('users')->orderBy('id')->value('id');

        $activity = $activityRepository->create([
            'type' => $type,
            'title' => '['.ucfirst($channel).' · '.$direction.'] '.$subject,
            'comment' => $note,
            'schedule_from' => $occurredAt,
            'schedule_to' => $occurredAt,
            'is_done' => 1,
            'user_id' => $ownerId,
            'additional' => json_encode(['channel' => $channel, 'direction' => $direction, 'dedupe' => $dedupe]),
        ]);

        $activity->leads()->attach($lead->id);

        // Optional stage change.
        $newStage = null;
        if (! empty($data['set_stage']) && in_array($data['set_stage'], $this->validStages, true)) {
            $stageId = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $lead->lead_pipeline_id)->where('code', $data['set_stage'])->value('id');
            if ($stageId) {
                $leadRepository->update(['entity_type' => 'leads', 'lead_pipeline_stage_id' => $stageId], $lead->id);
                $newStage = $data['set_stage'];
            }
        }

        return response()->json([
            'action' => 'logged',
            'activity_id' => $activity->id,
            'lead_id' => $lead->id,
            'stage' => $newStage,
        ]);
    }

    private function resolveSource(string $name): int
    {
        $id = DB::table('lead_sources')->where('name', $name)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('lead_sources')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
