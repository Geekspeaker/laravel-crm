<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — Phase 3 (3.7): stale-lead nudge.
 *
 * Tags open leads that have gone quiet (no activity for N days) with `stale`, so
 * they resurface in a filtered view instead of silently rotting. Run daily by the
 * scheduler (routes/console.php). Idempotent: a lead already tagged `stale` is
 * skipped; won/lost leads are never flagged. Time-based, so this is a scheduled
 * command rather than a Krayin workflow (workflows fire on create/update events).
 *
 *   php artisan skimify:flag-stale-leads --dry-run
 *   php artisan skimify:flag-stale-leads --days=21
 */
class FlagStaleLeads extends Command
{
    protected $signature = 'skimify:flag-stale-leads {--days=14 : Days of inactivity before a lead is stale} {--dry-run : Report without tagging}';

    protected $description = 'Tag open leads with no activity for N days as "stale".';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        $tagId = $this->ensureStaleTag();
        if (! $tagId) {
            $this->error('No users exist yet; cannot own the "stale" tag.');

            return self::FAILURE;
        }

        // Open leads (not won/lost) with their newest activity date.
        $leads = DB::table('leads')
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->leftJoin('lead_activities', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->where(function ($q) {
                $q->whereNull('lead_pipeline_stages.code')
                    ->orWhereNotIn('lead_pipeline_stages.code', ['won', 'lost']);
            })
            ->groupBy('leads.id', 'leads.created_at')
            ->select('leads.id', 'leads.created_at as lead_created', DB::raw('MAX(activities.created_at) as last_activity'))
            ->get();

        $flagged = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $lastTouch = $lead->last_activity ?: $lead->lead_created;
            if (! $lastTouch || Carbon::parse($lastTouch)->greaterThan($cutoff)) {
                continue; // still active
            }

            $already = DB::table('lead_tags')->where('lead_id', $lead->id)->where('tag_id', $tagId)->exists();
            if ($already) {
                $skipped++;

                continue;
            }

            $this->line(sprintf('  #%d  last touch %s%s', $lead->id, $lastTouch, $dryRun ? '  [dry-run]' : ''));

            if (! $dryRun) {
                DB::table('lead_tags')->insert(['lead_id' => $lead->id, 'tag_id' => $tagId]);
            }

            $flagged++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Newly stale: {$flagged} | Already tagged: {$skipped} (>{$days}d inactive)");

        return self::SUCCESS;
    }

    /** Find or create the `stale` tag (owned by the first user). */
    private function ensureStaleTag(): ?int
    {
        $id = DB::table('tags')->where('name', 'stale')->value('id');
        if ($id) {
            return (int) $id;
        }

        $userId = DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            return null;
        }

        return (int) DB::table('tags')->insertGetId([
            'name'       => 'stale',
            'color'      => '#857D94',
            'user_id'    => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
