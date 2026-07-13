<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the Outreach Copilot (skimify:draft-opener) for one lead on the queue, so the
 * dashboard "AI Draft" button returns instantly instead of blocking the single-threaded
 * web server (which caused 503s). Because it's off the request thread it can use the
 * website-grounding read.
 */
class GenerateLeadOpener implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public int $leadId,
        public bool $useWeb = true
    ) {}

    public function handle(): void
    {
        Artisan::call('skimify:draft-opener', [
            '--lead'   => $this->leadId,
            '--force'  => true,
            '--no-web' => ! $this->useWeb,
        ]);
    }
}
