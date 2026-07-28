<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — add a "Replied / Engaged" stage after Cold Email / Call.
 *
 * Fills the real gap in the funnel: a prospect who responded but has nothing booked
 * yet. Previously a reply had to land on a sourcing stage (VC "Warm Intro") or jump
 * straight to First Call. Segment-neutral, so it works for all four pipelines and
 * gives `advanceLeadOnReply` an honest target.
 *
 * Also retires the Publisher pipeline's now-redundant `contacted` stage (Cold Email /
 * Call + Replied / Engaged cover it). Any leads sitting on `contacted` are first moved
 * to `cold_outreach` so nothing is orphaned — the FK is ON DELETE SET NULL, which would
 * otherwise blank their stage.
 *
 * Idempotent: skips a pipeline that already has `replied`, and the Publisher cleanup
 * no-ops once `contacted` is gone.
 */
return new class extends Migration
{
    private array $pipelines = ['VC / Investors', 'HR / Work-Edu', 'Publishers', 'Brand / Offer-wall'];

    public function up(): void
    {
        // ---- 1. Insert "Replied / Engaged" right after cold_outreach ----
        foreach ($this->pipelines as $name) {
            $pipelineId = DB::table('lead_pipelines')->where('name', $name)->value('id');
            if (! $pipelineId) {
                continue;
            }

            $exists = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'replied')
                ->exists();
            if ($exists) {
                continue; // idempotent
            }

            $coldOrder = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'cold_outreach')
                ->value('sort_order');

            // Land right after cold outreach (or at position 2 if that stage is absent).
            $insertAt = $coldOrder !== null ? ((int) $coldOrder + 1) : 2;

            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('sort_order', '>=', $insertAt)
                ->increment('sort_order');

            DB::table('lead_pipeline_stages')->insert([
                'code'             => 'replied',
                'name'             => 'Replied / Engaged',
                'probability'      => 30,
                'sort_order'       => $insertAt,
                'lead_pipeline_id' => $pipelineId,
            ]);
        }

        // ---- 2. Retire the Publisher pipeline's redundant `contacted` stage ----
        $publisherId = DB::table('lead_pipelines')->where('name', 'Publishers')->value('id');
        if (! $publisherId) {
            return;
        }

        $contacted = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $publisherId)
            ->where('code', 'contacted')
            ->first(['id', 'sort_order']);

        if (! $contacted) {
            return; // already removed
        }

        // Move any leads off it first (FK is SET NULL — don't leave them stage-less).
        $fallbackId = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $publisherId)
            ->whereIn('code', ['cold_outreach', 'identified'])
            ->orderBy('sort_order')
            ->value('id');

        if ($fallbackId) {
            DB::table('leads')
                ->where('lead_pipeline_stage_id', $contacted->id)
                ->update(['lead_pipeline_stage_id' => $fallbackId, 'updated_at' => now()]);
        }

        DB::table('lead_pipeline_stages')->where('id', $contacted->id)->delete();

        DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $publisherId)
            ->where('sort_order', '>', $contacted->sort_order)
            ->decrement('sort_order');
    }

    public function down(): void
    {
        foreach ($this->pipelines as $name) {
            $pipelineId = DB::table('lead_pipelines')->where('name', $name)->value('id');
            if (! $pipelineId) {
                continue;
            }

            $order = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'replied')
                ->value('sort_order');

            DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', 'replied')
                ->delete();

            if ($order !== null) {
                DB::table('lead_pipeline_stages')
                    ->where('lead_pipeline_id', $pipelineId)
                    ->where('sort_order', '>', $order)
                    ->decrement('sort_order');
            }
        }

        // Publisher `contacted` is intentionally NOT restored (it was redundant).
    }
};
