<?php

namespace App\Console\Commands;

use App\Support\PipelineResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill existing leads onto their GTM segment pipeline (CRM GTM Upgrade Phase 2).
 *
 * Maps each lead's source → segment pipeline (PipelineResolver) and moves it there.
 * Non-destructive: only updates lead_pipeline_id + lead_pipeline_stage_id (real
 * columns), never deletes. Terminal state (won/lost) is preserved when the target
 * pipeline has the same code; otherwise the lead lands on the target's first stage.
 * Leads already on the correct pipeline are skipped. Use --dry-run to preview.
 *
 *   php artisan skimify:reassign-pipelines --dry-run
 *   php artisan skimify:reassign-pipelines
 */
class ReassignPipelines extends Command
{
    protected $signature = 'skimify:reassign-pipelines {--dry-run : Report changes without writing}';

    protected $description = 'Move existing leads onto their GTM segment pipeline (by lead source).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leads = DB::table('leads')
            ->leftJoin('lead_sources', 'leads.lead_source_id', '=', 'lead_sources.id')
            ->select('leads.id', 'leads.lead_pipeline_id', 'leads.lead_pipeline_stage_id', 'lead_sources.name as source_name')
            ->get();

        $moved = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $route = PipelineResolver::forSource($lead->source_name);
            $targetPipeline = $route['pipeline_id'];

            if ((int) $lead->lead_pipeline_id === $targetPipeline) {
                $skipped++;

                continue;
            }

            // Preserve won/lost across the move when the target pipeline has that code.
            $targetStageId = $route['stage_id'];
            $currentCode = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');
            if (in_array($currentCode, ['won', 'lost'], true)) {
                $mapped = PipelineResolver::stageId($targetPipeline, $currentCode);
                if ($mapped) {
                    $targetStageId = $mapped;
                }
            }

            $this->line(sprintf(
                '  #%d  %s → pipeline %d, stage %d%s',
                $lead->id, $lead->source_name ?? '(none)', $targetPipeline, $targetStageId, $dryRun ? '  [dry-run]' : ''
            ));

            if (! $dryRun) {
                DB::table('leads')->where('id', $lead->id)->update([
                    'lead_pipeline_id' => $targetPipeline,
                    'lead_pipeline_stage_id' => $targetStageId,
                ]);
            }

            $moved++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Moved: {$moved} | Already correct: {$skipped}");

        return self::SUCCESS;
    }
}
