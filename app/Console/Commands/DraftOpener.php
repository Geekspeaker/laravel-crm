<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * Outreach Copilot — research-grounded, thesis-matched opener generator.
 *
 * For each lead it builds a short dossier and a punchy, hook-first opener that
 * connects Skimify's thesis to THAT target's angle (VC thesis / partner category /
 * HR pain), optionally grounded by a quick read of the firm's website. Results are
 * saved to the lead's `ai_dossier` + `ai_draft` fields for review — never auto-sent.
 *
 * Usage:
 *   php artisan skimify:draft-opener --lead=42
 *   php artisan skimify:draft-opener --source="VC" --limit=10
 *   php artisan skimify:draft-opener --source="VC" --force --no-web
 */
class DraftOpener extends Command
{
    protected $signature = 'skimify:draft-opener
        {--lead= : A single lead id}
        {--source= : Only leads with this source name}
        {--stage= : Only leads in this pipeline stage code}
        {--limit=20 : Max leads to process in this run}
        {--force : Regenerate even if the lead already has an AI draft}
        {--no-web : Skip reading the company website for grounding}';

    protected $description = 'Generate research-grounded, thesis-matched outreach openers (Skimify voice).';

    /** Skimify facts the model must stay grounded in — never invent metrics. */
    private string $companyContext = <<<'CTX'
        COMPANY FACTS (do not invent users, revenue, or metrics — the company is pre-revenue):
        - Skimify News by Geekspeaker Inc. Founder/CEO: Golvis Tavarez (golvis@geekspeaker.com).
        - Gamified news app, LIVE on iOS + Android + web (skimify.news). TikTok-style vertical
          scroll, XP/levels/streaks, rewards.
        - Thesis: "reward attention, don't extract it." Data moat: attention + comprehension data —
          "everyone trains AI on what humans wrote; nobody trains on how humans think."
        - Raising $3M on a $30M post-money SAFE cap (~10%), pre-revenue. Roadmap includes SilentConvo
          (platform #2).
        CTX;

    /** Per-segment angle for the opener. */
    private array $segmentAngles = [
        'vc' => 'Investor outreach. Connect the attention/comprehension data moat to THIS partner\'s '
            .'stated thesis and recent consumer/AI bets. Lead with a pattern-break hook. Goal: a short intro call.',
        'partner' => 'Reward-partner/BD outreach. Connect their brand + audience to Skimify\'s rewards wall '
            .'(non-cash points, US/18+, web-completion, FTC-disclosed). Goal: explore putting their offer on the wall.',
        'hr' => 'Work/Edu design-partner outreach to senior HR/People/Comms. Connect to dead internal-comms '
            .'and low engagement; interactive content employees actually engage with. Goal: a design-partner pilot.',
        'general' => 'Cold outreach. Keep it specific and non-generic. Goal: a short reply/intro call.',
    ];

    public function handle(LeadRepository $leadRepository): int
    {
        $apiKey = core()->getConfigData('general.magic_ai.settings.api_key');
        $model = core()->getConfigData('general.magic_ai.settings.other_model')
            ?: core()->getConfigData('general.magic_ai.settings.model');
        $domain = core()->getConfigData('general.magic_ai.settings.api_domain') ?: 'https://api.openai.com/v1';

        if (! $apiKey || ! $model) {
            $this->error('Magic AI is not configured (set API key + model in Configuration → Magic AI).');

            return self::FAILURE;
        }

        $query = Lead::query()->with('person.organization', 'source');

        if ($leadId = $this->option('lead')) {
            $query->where('id', $leadId);
        }

        if ($sourceName = $this->option('source')) {
            $sourceId = DB::table('lead_sources')->where('name', $sourceName)->value('id');
            $query->where('lead_source_id', $sourceId ?: 0);
        }

        if ($stageCode = $this->option('stage')) {
            $stageId = DB::table('lead_pipeline_stages')->where('code', $stageCode)->value('id');
            $query->where('lead_pipeline_stage_id', $stageId ?: 0);
        }

        $leads = $query->limit((int) $this->option('limit') * 3)->get();

        $force = (bool) $this->option('force');
        $useWeb = ! $this->option('no-web');
        $limit = (int) $this->option('limit');

        $done = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($leads as $lead) {
            if ($done >= $limit) {
                break;
            }

            if (! $force && ! empty($lead->ai_draft)) {
                $skipped++;

                continue;
            }

            try {
                $context = $this->buildLeadContext($lead, $useWeb);
                $result = $this->generate($context, $model, $apiKey, $domain);

                if (! $result) {
                    $errors++;

                    continue;
                }

                $draft = trim(($result['email_subject'] ?? '')."\n\n".($result['email_body'] ?? ''));

                $leadUpdate = [
                    'entity_type' => 'leads',
                    'ai_dossier' => $result['dossier'] ?? '',
                    'ai_draft' => $draft,
                ];
                $leadCodes = ['ai_dossier', 'ai_draft'];

                if (! empty($result['thesis_fit'])) {
                    $leadUpdate['thesis_fit'] = $result['thesis_fit'];
                    $leadCodes[] = 'thesis_fit';
                }

                $leadRepository->update($leadUpdate, $lead->id, $leadCodes);

                $this->line("  ✓ #{$lead->id} {$lead->title}");
                $done++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  ✗ #{$lead->id}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done. Generated: {$done} | Skipped (already drafted): {$skipped} | Errors: {$errors}");

        return self::SUCCESS;
    }

    /**
     * Gather everything we know about the lead (+ optional website read).
     */
    private function buildLeadContext(Lead $lead, bool $useWeb): array
    {
        $person = $lead->person;
        $email = $person?->emails[0]['value'] ?? null;
        $company = $person?->organization?->name ?: '';
        $sourceName = strtolower(optional($lead->source)->name ?? '');

        $segment = 'general';
        if (str_contains($sourceName, 'vc') || str_contains($sourceName, 'invest')) {
            $segment = 'vc';
        } elseif (str_contains($sourceName, 'partner') || str_contains($sourceName, 'reward') || str_contains($sourceName, 'affiliate')) {
            $segment = 'partner';
        } elseif (str_contains($sourceName, 'hr') || str_contains($sourceName, 'work') || str_contains($sourceName, 'edu') || str_contains($sourceName, 'people')) {
            $segment = 'hr';
        }

        $website = '';
        if ($useWeb) {
            $website = $this->readWebsite($person?->website ?: null, $email);
        }

        return [
            'name' => $person?->name ?? '',
            'title' => $person?->job_title ?? '',
            'company' => $company,
            'email' => $email,
            'linkedin' => $person?->linkedin ?? '',
            'segment' => $segment,
            'website' => $website,
        ];
    }

    /**
     * Best-effort read of the firm's homepage for grounding. Skips personal/webmail
     * domains. Never fatal.
     */
    private function readWebsite(?string $websiteField, ?string $email): string
    {
        $host = null;

        if ($websiteField && preg_match('~^https?://~i', $websiteField)) {
            $host = parse_url($websiteField, PHP_URL_HOST);
        } elseif ($email && str_contains($email, '@')) {
            $domain = strtolower(trim(explode('@', $email)[1]));
            $generic = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'proton.me', 'aol.com'];
            if (! in_array($domain, $generic, true)) {
                $host = $domain;
            }
        }

        if (! $host) {
            return '';
        }

        try {
            $response = Http::timeout(10)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SkimifyCRM/1.0)'])
                ->get('https://'.$host);

            if ($response->failed()) {
                return '';
            }

            $text = preg_replace('/\s+/', ' ', strip_tags($response->body()));

            return mb_substr(trim($text), 0, 3500);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Call the model to produce a dossier + opener as JSON.
     */
    private function generate(array $ctx, string $model, string $apiKey, string $domain): ?array
    {
        $angle = $this->segmentAngles[$ctx['segment']] ?? $this->segmentAngles['general'];

        $user = "OUTREACH TARGET:\n"
            ."- Name: {$ctx['name']}\n"
            ."- Title: {$ctx['title']}\n"
            ."- Company/Firm: {$ctx['company']}\n"
            ."- LinkedIn: {$ctx['linkedin']}\n"
            ."- Segment angle: {$angle}\n"
            .($ctx['website'] ? "\nFIRM WEBSITE TEXT (for grounding — cite only what's here, don't invent):\n{$ctx['website']}\n" : '')
            ."\nTASKS:\n"
            .'1) dossier: 3-5 tight bullet points on who they are and the single best reason Skimify fits THEM. '
            ."Ground it in the facts/website above; if unsure, say so — never fabricate.\n"
            ."2) thesis_fit: ONE sharp sentence on how Skimify maps to THIS target's thesis/mandate/audience "
            ."(VC: their investing thesis; partner: audience/category fit; HR: the engagement pain). "
            ."Ground it; if you can't tell, say 'insufficient public info'.\n"
            ."3) email_subject: <= 6 words, specific, no clickbait.\n"
            ."4) email_body: Golvis's voice — PUNCHY, 4 lines max, hook-first (line 1 = a pattern-break tied to the "
            ."attention-data thesis and THIS person's angle). Plain text, no greeting fluff, one clear ask. "
            ."Sign as Golvis.\n\n"
            .'Respond ONLY as JSON: {"dossier": "...", "thesis_fit": "...", "email_subject": "...", "email_body": "..."}';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(rtrim($domain, '/').'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => "You are an outreach copilot for a pre-revenue startup. Be specific, grounded, and never invent metrics.\n\n".$this->companyContext],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

            if ($response->failed()) {
                $this->warn('  AI error [HTTP '.$response->status().']: '.mb_substr(trim($response->body()), 0, 200));

                return null;
            }

            $content = trim(preg_replace('/^```(json)?|```$/m', '', $response->json('choices.0.message.content') ?? ''));
            $data = json_decode($content, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->warn('  AI exception: '.$e->getMessage());

            return null;
        }
    }
}
