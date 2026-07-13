<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs PDL enrichment for one lead on the queue so the dashboard "Enrich" button
 * returns instantly instead of blocking the single-threaded web server.
 */
class EnrichLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $leadId) {}

    public function handle(): void
    {
        Artisan::call('skimify:enrich', [
            '--lead' => $this->leadId,
        ]);
    }
}
