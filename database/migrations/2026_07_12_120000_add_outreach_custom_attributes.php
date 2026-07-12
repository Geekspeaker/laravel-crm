<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds outreach-specific custom fields so contact/social data lives in real
 * attributes instead of being dumped into the lead description:
 *   - Person: linkedin, x_handle, website, alt_emails
 *   - Lead:   fit_score
 *
 * These appear automatically in the create/edit forms, detail pages, and list
 * columns, and are persisted by code via the attribute-value repositories.
 * Idempotent (insertOrIgnore + unique code/entity_type).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $attributes = [
            // --- Person social / contact fields ---
            [
                'code' => 'linkedin', 'name' => 'LinkedIn', 'type' => 'text',
                'entity_type' => 'persons', 'sort_order' => 20,
            ],
            [
                'code' => 'x_handle', 'name' => 'X (Twitter)', 'type' => 'text',
                'entity_type' => 'persons', 'sort_order' => 21,
            ],
            [
                'code' => 'website', 'name' => 'Website', 'type' => 'text',
                'entity_type' => 'persons', 'sort_order' => 22,
            ],
            [
                'code' => 'alt_emails', 'name' => 'Alternate Emails', 'type' => 'textarea',
                'entity_type' => 'persons', 'sort_order' => 23,
            ],
            // --- Lead field ---
            [
                'code' => 'fit_score', 'name' => 'Fit Score', 'type' => 'text',
                'entity_type' => 'leads', 'sort_order' => 20,
            ],
        ];

        foreach ($attributes as $attribute) {
            DB::table('attributes')->insertOrIgnore(array_merge([
                'lookup_type' => null,
                'validation' => null,
                'is_required' => 0,
                'is_unique' => 0,
                'quick_add' => 0,
                'is_user_defined' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $attribute));
        }
    }

    public function down(): void
    {
        DB::table('attributes')->whereIn('code', ['linkedin', 'x_handle', 'website', 'alt_emails'])
            ->where('entity_type', 'persons')->delete();

        DB::table('attributes')->where('code', 'fit_score')
            ->where('entity_type', 'leads')->delete();
    }
};
