<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — Phase 3 (3.4): seed starter lead tags.
 *
 * A small, reusable warmth/context tag set for outreach. `tags` has no unique
 * constraint on name, so we insert each only if absent (idempotent). Requires an
 * owner (tags.user_id is NOT NULL) — uses the first/admin user; if none exists yet
 * the seed no-ops (it re-runs safely on the next boot once a user exists).
 */
return new class extends Migration
{
    /** name => color (hex). */
    private array $tags = [
        'warm-intro' => '#F3BF3F',
        'inbound' => '#2DB4A0',
        'met-at-event' => '#7C5FB0',
        'portfolio-fit' => '#341C5B',
        'champion' => '#1F9D6B',
        'decision-maker' => '#E5604D',
    ];

    public function up(): void
    {
        $userId = DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            return; // no user yet; safe to seed on a later boot
        }

        $now = Carbon::now();

        foreach ($this->tags as $name => $color) {
            $exists = DB::table('tags')->where('name', $name)->exists();
            if ($exists) {
                continue;
            }

            DB::table('tags')->insert([
                'name' => $name,
                'color' => $color,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tags')->whereIn('name', array_keys($this->tags))->delete();
    }
};
