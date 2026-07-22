<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — Phase 2: per-segment pipelines.
 *
 * Seeds one pipeline per partner segment with its own ordered stages, plus the
 * `Publisher` lead source. Idempotent: pipelines are keyed on the unique `name`,
 * stages on the unique (code, lead_pipeline_id) — so boot-time `migrate --force`
 * re-runs never duplicate. The default pipeline (id 1) and all existing leads are
 * left untouched; routing (App\Support\PipelineRouter) sends new leads here by source.
 *
 * Schema (this Krayin 2.2 fork):
 *   lead_pipelines:       id, name (unique), is_default, rotten_days, timestamps
 *   lead_pipeline_stages: id, code, name, probability, sort_order, lead_pipeline_id
 *                         (no timestamps; unique(code, pipeline), unique(name, pipeline))
 */
return new class extends Migration
{
    /** pipeline name => ordered [code, name] stages. */
    private array $pipelines = [
        'VC / Investors' => [
            ['sourced', 'Sourced'],
            ['warm_intro', 'Warm Intro'],
            ['first_call', 'First Call'],
            ['partner_meeting', 'Partner Meeting'],
            ['data_room', 'Data Room'],
            ['term_sheet', 'Term Sheet'],
            ['won', 'Won'],
            ['lost', 'Lost'],
        ],
        'HR / Work-Edu' => [
            ['identified', 'Identified'],
            ['champion', 'Champion Engaged'],
            ['pilot_scoped', 'Pilot Scoped'],
            ['pilot_live', 'Pilot Live'],
            ['rollout', 'Rollout'],
            ['won', 'Won'],
            ['lost', 'Lost'],
        ],
        'Publishers' => [
            ['identified', 'Identified'],
            ['contacted', 'Contacted'],
            ['value_shared', 'Value Data Shared'],
            ['pilot_live', 'Pilot (Live on Feed)'],
            ['ongoing', 'Ongoing Partner'],
            ['lost', 'Lost'],
        ],
        'Brand / Offer-wall' => [
            ['prospect', 'Prospect'],
            ['terms', 'Terms'],
            ['compliance', 'Compliance / Creative'],
            ['live_on_wall', 'Live on Wall'],
            ['optimizing', 'Optimizing'],
            ['lost', 'Lost'],
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->pipelines as $pipelineName => $stages) {
            DB::table('lead_pipelines')->insertOrIgnore([
                'name' => $pipelineName,
                'is_default' => 0,
                'rotten_days' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $pipelineId = DB::table('lead_pipelines')->where('name', $pipelineName)->value('id');
            if (! $pipelineId) {
                continue;
            }

            $count = count($stages);
            foreach ($stages as $i => [$code, $name]) {
                DB::table('lead_pipeline_stages')->insertOrIgnore([
                    'code' => $code,
                    'name' => $name,
                    'probability' => $this->probabilityFor($code, $i, $count),
                    'sort_order' => $i + 1,
                    'lead_pipeline_id' => $pipelineId,
                ]);
            }
        }

        // Ensure the Publisher lead source exists (VC / HR / Reward Partner already do).
        DB::table('lead_sources')->insertOrIgnore([
            'name' => 'Publisher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Removing a pipeline cascades to its stages; leads' lead_pipeline_stage_id is
        // set null by FK. Safe, but we never run down in production.
        DB::table('lead_pipelines')->whereIn('name', array_keys($this->pipelines))->delete();
        DB::table('lead_sources')->where('name', 'Publisher')->delete();
    }

    /** Won/terminal-positive = 100, Lost = 0, otherwise ascending by position. */
    private function probabilityFor(string $code, int $index, int $count): int
    {
        if ($code === 'lost') {
            return 0;
        }

        if (in_array($code, ['won', 'ongoing'], true)) {
            return 100;
        }

        return (int) round((($index + 1) / $count) * 90);
    }
};
