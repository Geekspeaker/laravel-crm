<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — add a "Champion Engaged" stage to the Brand / Offer-wall pipeline.
 *
 * Inserted right before "Terms" (so: Prospect → Cold Email/Call → Champion Engaged →
 * Terms → …), matching the champion step used in the HR/EDU funnel. Anchored on the
 * `terms` stage's position so it lands correctly regardless of the cold-outreach stage.
 * Idempotent: skips if the pipeline already has a `champion` stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pipelineId = DB::table('lead_pipelines')->where('name', 'Brand / Offer-wall')->value('id');
        if (! $pipelineId) {
            return;
        }

        $exists = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', 'champion')
            ->exists();
        if ($exists) {
            return; // idempotent
        }

        $termsOrder = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', 'terms')
            ->value('sort_order');

        // Fall back to appending near the front if 'terms' somehow isn't present.
        $insertAt = $termsOrder !== null ? (int) $termsOrder : 3;

        DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('sort_order', '>=', $insertAt)
            ->increment('sort_order');

        DB::table('lead_pipeline_stages')->insert([
            'code' => 'champion',
            'name' => 'Champion Engaged',
            'probability' => 25,
            'sort_order' => $insertAt,
            'lead_pipeline_id' => $pipelineId,
        ]);
    }

    public function down(): void
    {
        $pipelineId = DB::table('lead_pipelines')->where('name', 'Brand / Offer-wall')->value('id');
        if (! $pipelineId) {
            return;
        }

        $order = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', 'champion')
            ->value('sort_order');

        DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', 'champion')
            ->delete();

        if ($order !== null) {
            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('sort_order', '>', $order)
                ->decrement('sort_order');
        }
    }
};
