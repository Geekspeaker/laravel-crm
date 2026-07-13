<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * People Data Labs enrichment. One Person Enrichment call per lead (by primary email)
 * fills the person (job_title, linkedin, website, alt_emails) AND company fields the
 * PDL person record carries (company_size, industry, region) — so we don't need the
 * scarce Company Enrichment credits. Only fills empty fields unless --force.
 *
 *   php artisan skimify:enrich --lead=42
 *   php artisan skimify:enrich --source="HR / Work-Edu" --limit=30
 */
class EnrichLeads extends Command
{
    protected $signature = 'skimify:enrich
        {--lead= : A single lead id}
        {--source= : Only leads with this source name}
        {--stage= : Only leads in this pipeline stage code}
        {--limit=50 : Max leads to process}
        {--force : Overwrite fields that already have a value}';

    protected $description = 'Enrich leads/contacts from People Data Labs (Person Enrichment API).';

    private const ENDPOINT = 'https://api.peopledatalabs.com/v5/person/enrich';

    public function handle(PersonRepository $personRepository, LeadRepository $leadRepository): int
    {
        $apiKey = env('PDL_API_KEY');

        if (! $apiKey) {
            $this->error('PDL_API_KEY is not set (add it as a Northflank env var).');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $query = Lead::query()->with('person.organization');

        if ($leadId = $this->option('lead')) {
            $query->where('id', $leadId);
        }

        if ($sourceName = $this->option('source')) {
            $query->where('lead_source_id', DB::table('lead_sources')->where('name', $sourceName)->value('id') ?: 0);
        }

        if ($stageCode = $this->option('stage')) {
            $query->where('lead_pipeline_stage_id', DB::table('lead_pipeline_stages')->where('code', $stageCode)->value('id') ?: 0);
        }

        $leads = $query->limit((int) $this->option('limit'))->get();

        $enriched = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($leads as $lead) {
            $person = $lead->person;
            $email = $person?->emails[0]['value'] ?? null;

            if (! $email) {
                continue;
            }

            try {
                $data = $this->pdlEnrich($apiKey, $email);
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  ✗ #{$lead->id}: ".$e->getMessage());
                usleep(700000);
                continue;
            }

            if ($data === null) {
                $notFound++;
                usleep(700000);
                continue;
            }

            $this->applyToPerson($personRepository, $person, $email, $data, $force);
            $this->applyToLead($leadRepository, $lead, $data, $force);

            $enriched++;
            $this->line("  ✓ #{$lead->id} {$lead->title}");

            usleep(700000); // stay under the 100/min rate limit
        }

        $this->newLine();
        $this->info("Done. Enriched: {$enriched} | Not found: {$notFound} | Errors: {$errors}");

        return self::SUCCESS;
    }

    /**
     * Call PDL Person Enrichment by email. Returns the data array or null (not found).
     */
    private function pdlEnrich(string $apiKey, string $email): ?array
    {
        $response = Http::withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(30)
            ->get(self::ENDPOINT, ['email' => $email, 'min_likelihood' => 6]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new \RuntimeException('PDL HTTP '.$response->status().': '.mb_substr(trim($response->body()), 0, 200));
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    /**
     * Update the person's job_title / linkedin / website / alt_emails.
     */
    private function applyToPerson(PersonRepository $repo, $person, string $email, array $data, bool $force): void
    {
        $update = [
            'entity_type' => 'persons',
            'emails'      => [['value' => $email, 'label' => 'work']],
            'user_id'     => $person->user_id,
        ];
        $codes = [];

        $set = function ($code, $value) use (&$update, &$codes, $person, $force) {
            if (! empty($value) && ($force || empty($person->{$code}))) {
                $update[$code] = $value;
                $codes[] = $code;
            }
        };

        $set('job_title', $data['job_title'] ?? null);
        $set('linkedin', $data['linkedin_url'] ?? null);
        $set('website', $data['job_company_website'] ?? null);

        // Alternate emails PDL found (exclude the primary).
        $emails = [];
        foreach (($data['emails'] ?? []) as $e) {
            if (! empty($e['address'])) {
                $emails[] = $e['address'];
            }
        }
        if (! empty($data['work_email'])) {
            $emails[] = $data['work_email'];
        }
        foreach (($data['personal_emails'] ?? []) as $pe) {
            $emails[] = $pe;
        }
        $alt = array_values(array_unique(array_filter($emails, fn ($e) => strtolower((string) $e) !== strtolower($email))));
        $set('alt_emails', $alt ? implode('; ', $alt) : null);

        if ($codes) {
            $repo->update($update, $person->id, $codes);
        }
    }

    /**
     * Update the lead's company_size / industry / region.
     */
    private function applyToLead(LeadRepository $repo, Lead $lead, array $data, bool $force): void
    {
        $region = $data['location_name']
            ?? trim(implode(', ', array_filter([$data['location_region'] ?? null, $data['location_country'] ?? null])));

        $fields = array_filter([
            'company_size' => $data['job_company_size'] ?? null,
            'industry'     => $data['job_company_industry'] ?? null,
            'region'       => $region ?: null,
        ], fn ($v) => ! empty($v));

        $update = ['entity_type' => 'leads'];
        $codes = [];

        foreach ($fields as $code => $value) {
            if ($force || empty($lead->{$code})) {
                $update[$code] = $value;
                $codes[] = $code;
            }
        }

        if ($codes) {
            $repo->update($update, $lead->id, $codes);
        }
    }
}
