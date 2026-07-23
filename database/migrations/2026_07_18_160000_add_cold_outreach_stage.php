<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — add a "Cold Email / Call" stage to each segment pipeline.
 *
 * Inserted at position 2 (right after Sourced / Identified / Prospect) so the cold
 * outreach touch is tracked before a lead engages. Existing stages at sort_order >= 2
 * shift up by one. Idempotent: skips a pipeline that already has the `cold_outreach`
 * stage, so boot `migrate --force` re-runs are safe. Segment pipelines only; the
 * default Krayin pipeline is left as-is.
 */
return new class extends Migration
{
    private array $pipelines = ['VC / Investors', 'HR / Work-Edu', 'Publishers', 'Brand / Offer-wall'];

    public function up(): void
    {
        foreach ($this->pipelines as $name) {
            $pipelineId = DB::table('lead_pipelines')->where('name', $name)->value('id');
            if (! $pipelineId) {
                continue;
            }

            $alreadyThere = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'cold_outreach')
                ->exists();
            if ($alreadyThere) {
                continue; // idempotent
            }

            // Make room at position 2 (sort_order has no unique constraint, so a bulk
            // increment is safe and preserves order).
            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('sort_order', '>=', 2)
                ->increment('sort_order');

            DB::table('lead_pipeline_stages')->insert([
                'code' => 'cold_outreach',
                'name' => 'Cold Email / Call',
                'probability' => 15,
                'sort_order' => 2,
                'lead_pipeline_id' => $pipelineId,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->pipelines as $name) {
            $pipelineId = DB::table('lead_pipelines')->where('name', $name)->value('id');
            if (! $pipelineId) {
                continue;
            }

            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'cold_outreach')
                ->delete();

            // Close the gap left behind.
            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('sort_order', '>', 2)
                ->decrement('sort_order');
        }
    }
};
