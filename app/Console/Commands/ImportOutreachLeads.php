<?php

namespace App\Console\Commands;

use App\Support\PipelineResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * Smart, AI-assisted outreach importer.
 *
 * Upload ANY CSV (any column names). The command reads just the header row and asks
 * the configured OpenAI model (Magic AI settings) to map columns to canonical fields
 * — ONE API call per file, not per row. It then deterministically builds an
 * Organization + Person + Lead for every row. Idempotent by email: re-running never
 * duplicates people or leads.
 *
 * Usage:
 *   php artisan skimify:import-leads storage/app/imports/vc.csv --source="VC" --stage=new
 *   php artisan skimify:import-leads leads.csv --no-ai --dry-run
 */
class ImportOutreachLeads extends Command
{
    protected $signature = 'skimify:import-leads
        {path : Path to the CSV file (absolute, or relative to the app root)}
        {--source= : Lead source name (created if it does not exist; default "Direct")}
        {--type=1 : Lead type id (1 = New Business)}
        {--pipeline=1 : Lead pipeline id (1 = default pipeline)}
        {--stage=new : Pipeline stage code (new, follow-up, prospect, negotiation, won, lost)}
        {--owner= : Owner user id (defaults to the first/admin user)}
        {--no-ai : Skip the AI header mapping and use deterministic fuzzy matching only}
        {--update : Refresh existing persons/leads (matched by email) instead of skipping — used to backfill}
        {--dry-run : Parse, map, and report — but write nothing}';

    protected $description = 'Smart AI-assisted import of any CSV into Organizations + Persons + Leads.';

    /** Canonical fields we map arbitrary headers onto. */
    private array $fields = [
        'first_name', 'last_name', 'full_name', 'email', 'phone',
        'job_title', 'company', 'linkedin', 'x_handle', 'website',
        'alt_emails', 'fit_score', 'fund_stage', 'check_size', 'thesis_fit',
        'affiliate_network', 'category', 'payout_model', 'company_size',
        'industry', 'region', 'priority',
        'warm_intro_path', 'seats_or_students', 'edu_semester', 'ferpa_flag',
        'domain', 'feed_type', 'monthly_traffic', 'outbound_clicks_sent',
        'audience_fit', 'compliance_flag',
        'notes', 'ignore',
    ];

    /** Custom attribute codes that live on the Lead entity. */
    private array $leadAttributeFields = [
        'fit_score', 'fund_stage', 'check_size', 'thesis_fit', 'affiliate_network',
        'category', 'payout_model', 'company_size', 'industry', 'region', 'priority',
        'warm_intro_path', 'seats_or_students', 'edu_semester', 'ferpa_flag',
        'domain', 'feed_type', 'monthly_traffic', 'outbound_clicks_sent',
        'audience_fit', 'compliance_flag',
    ];

    public function handle(
        PersonRepository $personRepository,
        LeadRepository $leadRepository
    ): int {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("File not found: {$this->argument('path')}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        if (count($rows) < 2) {
            $this->error('CSV appears empty (need a header row + at least one data row).');

            return self::FAILURE;
        }

        $headers = array_shift($rows);

        // ---- Resolve the column -> field mapping (AI first, fuzzy fallback) -------
        $mapping = $this->option('no-ai')
            ? $this->fuzzyMap($headers)
            : ($this->aiMap($headers) ?: $this->fuzzyMap($headers));

        $this->info('Detected column mapping:');
        foreach ($headers as $i => $h) {
            $this->line(sprintf('  %-28s -> %s', $h, $mapping[$i] ?? 'ignore'));
        }

        // ---- Resolve owner / source / type / pipeline / stage ---------------------
        $ownerId = $this->option('owner') ?: DB::table('users')->orderBy('id')->value('id');
        if (! $ownerId) {
            $this->error('No users found. Create an admin first (krayin-crm:install).');

            return self::FAILURE;
        }

        $sourceName = $this->option('source') ?: 'Direct';
        $sourceId = $this->resolveSource($sourceName);
        $typeId = (int) $this->option('type');

        // Route to the segment's pipeline + first stage (unknown source → default
        // pipeline 1 / 'new'). An explicit --stage wins only if it exists on that pipeline.
        $route = PipelineResolver::forSource($sourceName);
        $pipelineId = $route['pipeline_id'];
        $stageId = $route['stage_id'];

        if ($this->option('stage')) {
            $overrideId = PipelineResolver::stageId($pipelineId, $this->option('stage'));
            if ($overrideId) {
                $stageId = $overrideId;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        $update = (bool) $this->option('update');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing '.count($rows).' rows...');
        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $n => $row) {
            $bar->advance();

            try {
                $data = $this->mapRow($headers, $mapping, $row);

                $email = strtolower(trim($data['email'] ?? ''));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;

                    continue;
                }

                $name = trim($data['full_name'] ?? '')
                    ?: trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
                $name = $name ?: $email;

                $company = trim($data['company'] ?? '');
                $title = trim($data['job_title'] ?? '');

                if ($dryRun) {
                    $created++;

                    continue;
                }

                // Person social/contact custom-attribute values (only the ones present).
                $personAttrs = array_filter([
                    'linkedin' => $data['linkedin'] ?? null,
                    'x_handle' => $data['x_handle'] ?? null,
                    'website' => $data['website'] ?? null,
                    'alt_emails' => $data['alt_emails'] ?? null,
                ], fn ($v) => ! is_null($v) && $v !== '');

                // Idempotency: reuse an existing person by email; create if absent.
                $person = Person::whereJsonContains('emails', [['value' => $email]])->first();

                if (! $person) {
                    $person = $personRepository->create(array_filter(array_merge([
                        'name' => $name,
                        'emails' => [['value' => $email, 'label' => 'work']],
                        'job_title' => $title ?: null,
                        'organization_name' => $company ?: null,
                        'user_id' => $ownerId,
                        'entity_type' => 'persons',
                    ], $personAttrs), fn ($v) => ! is_null($v)));
                } elseif ($update && $personAttrs) {
                    // Backfill: refresh the social fields on an existing person.
                    $personRepository->update(array_merge($personAttrs, [
                        'entity_type' => 'persons',
                        'emails' => [['value' => $email, 'label' => 'work']],
                        'user_id' => $person->user_id,
                    ]), $person->id, array_keys($personAttrs));
                }

                // Persons imported without a phone must store [] (not null), else the
                // detail view foreach()es null and 500s.
                if (is_null($person->contact_numbers)) {
                    $person->contact_numbers = [];
                    $person->save();
                }

                $existingLead = Lead::where('person_id', $person->id)->first();

                if ($existingLead) {
                    if ($update) {
                        // Backfill: clear the old description dump + set any present
                        // lead custom attributes (fit_score, fund_stage, ...).
                        $leadData = ['entity_type' => 'leads', 'description' => $data['notes'] ?? ''];
                        $leadCodes = ['description'];

                        foreach ($this->leadAttributeFields as $f) {
                            if (! empty($data[$f])) {
                                $leadData[$f] = $data[$f];
                                $leadCodes[] = $f;
                            }
                        }

                        $leadRepository->update($leadData, $existingLead->id, $leadCodes);
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $leadData = [
                    'title' => $company ? "{$name} - {$company}" : $name,
                    'description' => $data['notes'] ?? null,
                    'lead_value' => 0,
                    'status' => 1,
                    'person_id' => $person->id,
                    'lead_source_id' => $sourceId,
                    'lead_type_id' => $typeId,
                    'lead_pipeline_id' => $pipelineId,
                    'lead_pipeline_stage_id' => $stageId,
                    'user_id' => $ownerId,
                    'entity_type' => 'leads',
                ];

                foreach ($this->leadAttributeFields as $f) {
                    if (! empty($data[$f])) {
                        $leadData[$f] = $data[$f];
                    }
                }

                $leadRepository->create($leadData);

                $created++;
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->warn('Row '.($n + 2).' failed: '.$e->getMessage());
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            '%sDone. Created: %d | Updated: %d | Skipped: %d | Errors: %d',
            $dryRun ? '[DRY RUN] ' : '', $created, $updated, $skipped, $errors
        ));

        return self::SUCCESS;
    }

    /**
     * Read a CSV file into an array of rows.
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            while (($row = fgetcsv($h, 0, ',')) !== false) {
                // Skip completely blank lines.
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue;
                }
                $rows[] = $row;
            }
            fclose($h);
        }

        return $rows;
    }

    /**
     * Ask the configured OpenAI model to map headers -> canonical fields.
     * Returns [columnIndex => field] or null on failure.
     */
    private function aiMap(array $headers): ?array
    {
        $apiKey = core()->getConfigData('general.magic_ai.settings.api_key');
        $model = core()->getConfigData('general.magic_ai.settings.other_model')
            ?: core()->getConfigData('general.magic_ai.settings.model');
        $domain = core()->getConfigData('general.magic_ai.settings.api_domain') ?: 'https://api.openai.com/v1';

        if (! $apiKey || ! $model) {
            $this->warn('Magic AI not configured; using fuzzy header matching.');

            return null;
        }

        $fieldList = implode(', ', $this->fields);
        $prompt = "Map each CSV column header to exactly one canonical field.\n"
            ."Canonical fields: {$fieldList}.\n"
            ."Use \"ignore\" for anything that does not fit.\n"
            .'Headers: '.json_encode(array_values($headers))."\n"
            .'Respond ONLY with a JSON object of {"header":"field"}.';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post(rtrim($domain, '/').'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You map spreadsheet headers to fields. Reply with JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                $this->warn(sprintf(
                    'AI mapping failed [HTTP %d] using model "%s" at %s: %s',
                    $response->status(),
                    $model,
                    rtrim($domain, '/').'/chat/completions',
                    mb_substr(trim($response->body()), 0, 400)
                ));
                $this->warn('Falling back to fuzzy matching.');

                return null;
            }

            $content = $response->json('choices.0.message.content') ?? '';
            $content = trim(preg_replace('/^```(json)?|```$/m', '', $content));
            $map = json_decode($content, true);

            if (! is_array($map)) {
                return null;
            }

            // Convert {header => field} to [index => field].
            $result = [];
            foreach ($headers as $i => $h) {
                $field = $map[$h] ?? 'ignore';
                $result[$i] = in_array($field, $this->fields, true) ? $field : 'ignore';
            }

            return $result;
        } catch (\Throwable $e) {
            $this->warn('AI mapping error ('.$e->getMessage().'); using fuzzy matching.');

            return null;
        }
    }

    /**
     * Deterministic fuzzy header matching (no API cost). Fallback when AI is off/unavailable.
     */
    private function fuzzyMap(array $headers): array
    {
        $synonyms = [
            'first_name' => ['first', 'firstname', 'fname', 'givenname'],
            'last_name' => ['last', 'lastname', 'lname', 'surname', 'familyname'],
            'full_name' => ['name', 'fullname', 'contact', 'contactname', 'person'],
            'email' => ['email', 'emails', 'emailaddress', 'mail', 'workemail', 'e'],
            'phone' => ['phone', 'phonenumber', 'mobile', 'cell', 'tel', 'contactnumber'],
            'job_title' => ['title', 'jobtitle', 'role', 'position', 'designation'],
            'company' => ['company', 'organization', 'organisation', 'org', 'employer', 'firm', 'account'],
            'linkedin' => ['linkedin', 'linkedinurl', 'li', 'profile'],
            'x_handle' => ['x', 'xhandle', 'twitter', 'twitterhandle', 'twitterurl'],
            'website' => ['website', 'web', 'url', 'site', 'homepage'],
            'alt_emails' => ['altemails', 'alternateemails', 'allemails', 'otheremails', 'secondaryemail'],
            'fit_score' => ['fitscore', 'fit', 'score'],
            'fund_stage' => ['fundstage', 'stage', 'stagefocus', 'investmentstage', 'round'],
            'check_size' => ['checksize', 'check', 'ticket', 'ticketsize', 'investmentsize'],
            'thesis_fit' => ['thesisfit', 'thesis', 'fitnotes'],
            'affiliate_network' => ['affiliatenetwork', 'network', 'affiliate', 'platform'],
            'category' => ['category', 'vertical', 'segmentcategory'],
            'payout_model' => ['payoutmodel', 'payout', 'commission', 'commissionmodel', 'cpamodel'],
            'company_size' => ['companysize', 'headcount', 'employees', 'employeecount', 'size'],
            'industry' => ['industry', 'sector'],
            'region' => ['region', 'location', 'geo', 'country', 'market'],
            'priority' => ['priority', 'tier', 'rank'],
            'notes' => ['notes', 'note', 'description', 'comment', 'comments', 'leadsource', 'leadstatus'],
        ];

        $result = [];
        foreach ($headers as $i => $h) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower((string) $h));
            $result[$i] = 'ignore';
            foreach ($synonyms as $field => $keys) {
                if (in_array($norm, $keys, true)) {
                    $result[$i] = $field;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Map a raw row to canonical fields. Later columns don't overwrite earlier
     * non-empty values for the same field (except notes, which accumulate).
     */
    private function mapRow(array $headers, array $mapping, array $row): array
    {
        $data = [];
        foreach ($headers as $i => $h) {
            $field = $mapping[$i] ?? 'ignore';
            if ($field === 'ignore') {
                continue;
            }
            $value = trim((string) ($row[$i] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($field === 'notes') {
                $data['notes'] = trim(($data['notes'] ?? '')."\n".$h.': '.$value);
            } elseif (empty($data[$field])) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * Find or create a lead source by name; return its id.
     */
    private function resolveSource(string $name): int
    {
        $id = DB::table('lead_sources')->where('name', $name)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('lead_sources')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
