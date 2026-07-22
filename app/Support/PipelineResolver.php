<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Maps a lead source (segment) to its dedicated pipeline + first stage.
 *
 * The four GTM segments each have their own pipeline (seeded by
 * 2026_07_18_*_seed_segment_pipelines). Unknown/missing sources fall back to the
 * default pipeline (id 1, stage 'new') so the write API + importer stay backward
 * compatible. See .kiro/specs/crm-gtm-upgrade.
 */
class PipelineResolver
{
    /** Source name → [pipeline name, first-stage code]. */
    private const SOURCE_MAP = [
        'VC' => ['VC / Investors', 'sourced'],
        'HR / Work-Edu' => ['HR / Work-Edu', 'identified'],
        'Publisher' => ['Publishers', 'identified'],
        'Reward Partner' => ['Brand / Offer-wall', 'prospect'],
    ];

    /**
     * Resolve the pipeline + first stage for a lead source.
     *
     * @return array{pipeline_id:int, stage_id:int, stage_code:string}
     */
    public static function forSource(?string $source): array
    {
        $entry = self::SOURCE_MAP[trim((string) $source)] ?? null;

        if ($entry) {
            $pipelineId = DB::table('lead_pipelines')->where('name', $entry[0])->value('id');

            if ($pipelineId) {
                $stageId = self::stageId((int) $pipelineId, $entry[1]);

                if ($stageId) {
                    return ['pipeline_id' => (int) $pipelineId, 'stage_id' => $stageId, 'stage_code' => $entry[1]];
                }
            }
        }

        // Fallback: default pipeline 1 / 'new' (back-compat for unknown sources).
        return [
            'pipeline_id' => 1,
            'stage_id' => self::stageId(1, 'new') ?: 1,
            'stage_code' => 'new',
        ];
    }

    /**
     * Look up a stage id by code within a pipeline. Returns null if that code does
     * not exist on the pipeline (segment pipelines use segment-specific codes).
     */
    public static function stageId(int $pipelineId, string $code): ?int
    {
        $id = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', $code)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
