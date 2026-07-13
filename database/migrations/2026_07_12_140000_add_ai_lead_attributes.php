<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI opener fields on the Lead:
 *   - ai_dossier : a short research brief the copilot builds for the lead
 *   - ai_draft   : the generated, thesis-matched, punchy opener (subject + body)
 *
 * Populated by `php artisan skimify:draft-opener`. Universal fields (shown on every
 * lead regardless of source). Idempotent (insertOrIgnore).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        foreach ([
            'ai_dossier' => ['name' => 'AI Dossier', 'sort' => 40],
            'ai_draft' => ['name' => 'AI Draft Opener', 'sort' => 41],
        ] as $code => $meta) {
            DB::table('attributes')->insertOrIgnore([
                'code' => $code,
                'name' => $meta['name'],
                'type' => 'textarea',
                'entity_type' => 'leads',
                'lookup_type' => null,
                'validation' => null,
                'sort_order' => $meta['sort'],
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
            ->whereIn('code', ['ai_dossier', 'ai_draft'])->delete();
    }
};
