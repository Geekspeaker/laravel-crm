<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM GTM upgrade — Phase 2 (2.7): seed Collateral items.
 *
 * The Products module is repurposed as "Collateral" (Phase 1 relabel) — the assets
 * we send a partner. This seeds one row per segment asset so they're pickable on a
 * lead (via the existing lead↔product attachment). Idempotent: keyed on the unique
 * `sku` (`insertOrIgnore`), safe under boot `migrate --force`. No schema change.
 */
return new class extends Migration
{
    /** sku => [name, description]. */
    private array $collateral = [
        'COLL-VC-DECK'        => ['VC Deck', 'Skimify investor pitch deck (VC / Investors).'],
        'COLL-PUB-DECK'       => ['Publisher Deck', 'Publisher partnership deck.'],
        'COLL-PUB-VALUE'      => ['Publisher Value Report', 'Outbound-clicks value report shared with a publisher.'],
        'COLL-HR-ONEPAGER'    => ['HR One-Pager', 'Work / Edu HR one-pager.'],
        'COLL-EDU-DECK'       => ['EDU Deck', 'Student-Services / EDU deck.'],
        'COLL-BRAND-ONEPAGER' => ['Offer-wall One-Pager', 'Brand / offer-wall partnership one-pager.'],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->collateral as $sku => [$name, $description]) {
            DB::table('products')->insertOrIgnore([
                'sku'         => $sku,
                'name'        => $name,
                'description' => $description,
                'quantity'    => 1,      // collateral isn't inventory; keep it "available"
                'price'       => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('products')->whereIn('sku', array_keys($this->collateral))->delete();
    }
};
