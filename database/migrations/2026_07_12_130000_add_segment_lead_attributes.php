<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Segment-specific outreach fields on the Lead entity. Attributes are per-entity
 * (they show on every lead) — the lead source tells you which segment a lead
 * belongs to, so VC-only fields simply stay empty for partner/HR leads.
 *
 *   VC:            fund_stage, check_size, thesis_fit
 *   Reward Partner: affiliate_network, category, payout_model
 *   HR / Work-Edu:  company_size, industry, region
 *   Universal:      priority
 *
 * Idempotent (insertOrIgnore).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $codes = [
            'fund_stage'        => 'Fund Stage',
            'check_size'        => 'Check Size',
            'thesis_fit'        => 'Thesis Fit',
            'affiliate_network' => 'Affiliate Network',
            'category'          => 'Category',
            'payout_model'      => 'Payout Model',
            'company_size'      => 'Company Size',
            'industry'          => 'Industry',
            'region'            => 'Region',
            'priority'          => 'Priority',
        ];

        $sort = 21;

        foreach ($codes as $code => $name) {
            DB::table('attributes')->insertOrIgnore([
                'code'            => $code,
                'name'            => $name,
                'type'            => 'text',
                'entity_type'     => 'leads',
                'lookup_type'     => null,
                'validation'      => null,
                'sort_order'      => $sort++,
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 0,
                'is_user_defined' => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('attributes')->where('entity_type', 'leads')->whereIn('code', [
            'fund_stage', 'check_size', 'thesis_fit', 'affiliate_network', 'category',
            'payout_model', 'company_size', 'industry', 'region', 'priority',
        ])->delete();
    }
};
