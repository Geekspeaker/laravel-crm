<?php

namespace App\Http\Controllers;

use App\Jobs\EnrichLead;
use App\Jobs\GenerateLeadOpener;
use Illuminate\Routing\Controller;

/**
 * Kicks off the Outreach Copilot for a single lead from the dashboard "AI Draft"
 * button. Dispatches to the queue and returns immediately so the single-threaded web
 * server never blocks on the AI call (which was causing 503s). The queue worker
 * generates the dossier / thesis fit / opener in the background.
 */
class LeadAiController extends Controller
{
    public function draft($id)
    {
        GenerateLeadOpener::dispatch((int) $id, useWeb: true);

        return response()->json([
            'message' => 'Generating the AI dossier + opener in the background — refresh in ~30s.',
        ]);
    }

    public function enrich($id)
    {
        EnrichLead::dispatch((int) $id);

        return response()->json([
            'message' => 'Enriching from People Data Labs in the background — refresh in ~20s.',
        ]);
    }
}
