<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — Phase 3 (3.1): expanded per-segment lead attributes.
 *
 * Adds the signature fields each segment needs, on top of the Phase-1/2 set:
 *   VC:        warm_intro_path
 *   HR/EDU:    seats_or_students, edu_semester, ferpa_flag (bool)
 *   Publisher: domain, feed_type, monthly_traffic, outbound_clicks_sent
 *   Brand:     audience_fit, compliance_flag (bool)
 *
 * Source-scoped views (leads/view + leads/edit blades) show only the active
 * segment's fields. Idempotent: insertOrIgnore on unique (code, entity_type).
 */
return new class extends Migration
{
    /** code => [name, type]. */
    private array $attributes = [
        'warm_intro_path' => ['Warm Intro Path', 'text'],
        'seats_or_students' => ['Seats / Students', 'text'],
        'edu_semester' => ['Academic Term', 'text'],
        'ferpa_flag' => ['FERPA Sensitive', 'boolean'],
        'domain' => ['Domain', 'text'],
        'feed_type' => ['Feed / CMS Type', 'text'],
        'monthly_traffic' => ['Monthly Traffic', 'text'],
        'outbound_clicks_sent' => ['Outbound Clicks Sent', 'text'],
        'audience_fit' => ['Audience Fit', 'text'],
        'compliance_flag' => ['Compliance Reviewed', 'boolean'],
    ];

    public function up(): void
    {
        $now = Carbon::now();
        $sort = 50;

        foreach ($this->attributes as $code => [$name, $type]) {
            DB::table('attributes')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'entity_type' => 'leads',
                'lookup_type' => null,
                'validation' => null,
                'sort_order' => $sort++,
                'is_required' => 0,
                'is_unique' => 0,
                'quick_add' => 0,
                'is_user_defined' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('attributes')->where('entity_type', 'leads')
            ->whereIn('code', array_keys($this->attributes))->delete();
    }
};
