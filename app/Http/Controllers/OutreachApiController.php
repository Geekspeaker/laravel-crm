<?php

namespace App\Http\Controllers;

use App\Support\PipelineResolver;
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
 *   POST /api/outreach/leads/upsert  — create/update Org+Person+Lead
 *   POST /api/outreach/touches       — append an activity + optionally set the stage
 *
 * Identity: email is the PRIMARY key. When email is absent we accept an alternate
 * key so LinkedIn-only contacts can still be logged — a `linkedin` URL, or
 * `name`(+ first/last) + `company`. We never fabricate a placeholder email.
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
        $hasEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $linkedin = trim($data['linkedin'] ?? '');
        $company = trim($data['company'] ?? '');
        $title = trim($data['title'] ?? '');

        // Raw (non-synthesized) name, used both for the record and for matching.
        $rawName = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: trim($data['name'] ?? '');

        // Identity gate: need email OR linkedin OR name+company. Never invent an email.
        if (! $hasEmail && $linkedin === '' && ! ($rawName !== '' && $company !== '')) {
            return response()->json([
                'error' => 'missing_identifier',
                'message' => 'Provide a valid "email", or a "linkedin" URL, or "name" (or first/last) + "company".',
            ], 422);
        }

        $name = $rawName !== '' ? $rawName : $this->fallbackName($hasEmail, $email, $company, $linkedin);

        $ownerId = DB::table('users')->orderBy('id')->value('id');
        $sourceName = $data['source'] ?? 'Direct';
        $sourceId = $this->resolveSource($sourceName);

        // Route to the segment's pipeline + first stage (unknown source → default
        // pipeline 1 / 'new'). An explicit `stage` wins only if that code exists on the
        // resolved pipeline (segment pipelines use segment-specific stage codes).
        $route = PipelineResolver::forSource($sourceName);
        $pipelineId = $route['pipeline_id'];
        $stageId = $route['stage_id'];
        $stageCode = $route['stage_code'];

        if (! empty($data['stage'])) {
            $overrideId = PipelineResolver::stageId($pipelineId, $data['stage']);
            if ($overrideId) {
                $stageId = $overrideId;
                $stageCode = $data['stage'];
            }
        }

        // ---- Person (resolve by email → linkedin → name+company, else create) ----
        $personAttrs = [];
        foreach ($this->personAttributeFields as $f) {
            if (! empty($data[$f])) {
                $personAttrs[$f] = $data[$f];
            }
        }

        $matchedBy = null;
        $person = $this->resolvePerson($data, $matchedBy);

        if (! $person) {
            $matchedBy = 'created';

            $person = $personRepository->create(array_filter(array_merge([
                'name' => $name,
                'emails' => $hasEmail ? [['value' => $email, 'label' => 'work']] : [],
                'job_title' => $title ?: null,
                'organization_name' => $company ?: null,
                'user_id' => $ownerId,
                'entity_type' => 'persons',
            ], $personAttrs), fn ($v) => ! is_null($v)));
        } else {
            // Preserve existing emails; backfill a primary email onto a contact we
            // first met LinkedIn-only. Never wipe what's already there.
            $emails = $person->emails ?: [];
            if ($hasEmail && empty($emails)) {
                $emails = [['value' => $email, 'label' => 'work']];
            }

            $update = [
                'entity_type' => 'persons',
                'user_id' => $person->user_id,
                'emails' => $emails,
            ];
            $codes = [];

            foreach ($personAttrs as $code => $val) {
                $update[$code] = $val;
                $codes[] = $code;
            }

            if ($title && empty($person->job_title)) {
                $update['job_title'] = $title;
                $codes[] = 'job_title';
            }

            // Link an organization if the contact doesn't have one yet.
            if ($company !== '' && empty($person->organization_id)) {
                $update['organization_name'] = $company;
            }

            $personRepository->update($update, $person->id, $codes);
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

            // Move the stage only if the caller passed one that exists on THIS lead's
            // pipeline (never silently move the lead to a different pipeline on upsert).
            if (! empty($data['stage'])) {
                $moveId = PipelineResolver::stageId((int) $lead->lead_pipeline_id, $data['stage']);
                if ($moveId) {
                    $update['lead_pipeline_stage_id'] = $moveId;
                }
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
            'matched_by' => $matchedBy,
        ]);
    }

    public function logTouch(Request $request, ActivityRepository $activityRepository, LeadRepository $leadRepository)
    {
        $data = $request->all();

        // Resolve the lead by id, or by the person (email → linkedin → name+company).
        $lead = null;
        if (! empty($data['lead_id'])) {
            $lead = Lead::find($data['lead_id']);
        } else {
            $person = $this->resolvePerson($data);
            $lead = $person ? Lead::where('person_id', $person->id)->first() : null;
        }

        if (! $lead) {
            return response()->json(['error' => 'lead_not_found', 'message' => 'No lead matched lead_id/email/linkedin/name+company.'], 404);
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

        // Optional stage change — resolved against THIS lead's pipeline, so both the
        // default funnel codes and segment-specific codes work.
        $newStage = null;
        if (! empty($data['set_stage'])) {
            $stageId = PipelineResolver::stageId((int) $lead->lead_pipeline_id, $data['set_stage']);
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

    /**
     * Resolve a Person from the request payload using, in order: a valid email,
     * a linkedin URL, then name + company. Sets $matchedBy to the winning key.
     */
    private function resolvePerson(array $data, ?string &$matchedBy = null): ?Person
    {
        $email = strtolower(trim($data['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($person = Person::whereJsonContains('emails', [['value' => $email]])->first()) {
                $matchedBy = 'email';

                return $person;
            }
        }

        if (! empty($data['linkedin'])) {
            if ($person = $this->findPersonByLinkedin((string) $data['linkedin'])) {
                $matchedBy = 'linkedin';

                return $person;
            }
        }

        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: trim($data['name'] ?? '');
        $company = trim($data['company'] ?? '');

        if ($name !== '' && $company !== '') {
            if ($person = $this->findPersonByNameCompany($name, $company)) {
                $matchedBy = 'name_company';

                return $person;
            }
        }

        return null;
    }

    /**
     * Find a Person by their linkedin custom attribute. Because `linkedin` is an EAV
     * text attribute (stored in attribute_values.text_value for entity_type
     * 'persons'), we narrow by the vanity slug then compare on a normalized URL so
     * trivial differences (scheme, www., trailing slash, case) still match.
     */
    private function findPersonByLinkedin(string $linkedin): ?Person
    {
        $linkedin = trim($linkedin);
        if ($linkedin === '') {
            return null;
        }

        $attrId = DB::table('attributes')
            ->where('entity_type', 'persons')
            ->where('code', 'linkedin')
            ->value('id');

        if (! $attrId) {
            return null;
        }

        $normalized = $this->normalizeLinkedin($linkedin);
        $slug = $this->linkedinSlug($normalized);

        $rows = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('attribute_id', $attrId)
            ->where('text_value', 'like', '%'.$slug.'%')
            ->orderBy('entity_id')
            ->get(['entity_id', 'text_value']);

        foreach ($rows as $row) {
            if ($this->normalizeLinkedin((string) $row->text_value) === $normalized) {
                return Person::find($row->entity_id);
            }
        }

        return null;
    }

    /**
     * Find a Person by exact (case-insensitive) name within an organization matched
     * by (case-insensitive) name.
     */
    private function findPersonByNameCompany(string $name, string $company): ?Person
    {
        $orgId = DB::table('organizations')
            ->whereRaw('LOWER(name) = ?', [strtolower($company)])
            ->value('id');

        if (! $orgId) {
            return null;
        }

        return Person::whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('organization_id', $orgId)
            ->first();
    }

    /**
     * Reduce a linkedin URL to a comparable form: lowercase, no scheme, no www.,
     * no trailing slash (e.g. "linkedin.com/in/markkirkham").
     */
    private function normalizeLinkedin(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~^https?://~', '', $value);
        $value = preg_replace('~^www\.~', '', $value);

        return rtrim($value, '/');
    }

    /**
     * Last path segment of a normalized linkedin URL (the vanity slug).
     */
    private function linkedinSlug(string $normalized): string
    {
        $parts = array_values(array_filter(explode('/', $normalized), fn ($p) => $p !== ''));

        return end($parts) ?: $normalized;
    }

    /**
     * Best-effort display name when no name was supplied (email-less contacts).
     */
    private function fallbackName(bool $hasEmail, string $email, string $company, string $linkedin): string
    {
        if ($hasEmail) {
            return $email;
        }

        if ($company !== '') {
            return $company;
        }

        if ($linkedin !== '') {
            return $this->linkedinSlug($this->normalizeLinkedin($linkedin));
        }

        return 'Unknown';
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
