<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * Triggers the Outreach Copilot (skimify:draft-opener) for a single lead from the
 * dashboard "AI Draft" button. Runs synchronously (no queue worker in this deploy),
 * so it's called with --no-web to stay fast and avoid blocking the single-threaded
 * server; the bulk CLI run can use website grounding.
 */
class LeadAiController extends Controller
{
    public function draft($id)
    {
        $exit = Artisan::call('skimify:draft-opener', [
            '--lead' => $id,
            '--force' => true,
            '--no-web' => true,
        ]);

        return response()->json([
            'message' => $exit === 0
                ? 'AI dossier, thesis fit, and draft opener generated.'
                : 'AI generation finished with issues — check the fields or logs.',
        ]);
    }
}
