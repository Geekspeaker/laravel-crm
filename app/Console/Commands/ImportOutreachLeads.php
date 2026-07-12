<?php

namespace App\Console\Commands;

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
        {--dry-run : Parse, map, and report — but write nothing}';

    protected $description = 'Smart AI-assisted import of any CSV into Organizations + Persons + Leads.';

    /** Canonical fields we map arbitrary headers onto. */
    private array $fields = [
        'first_name', 'last_name', 'full_name', 'email',
        'phone', 'job_title', 'company', 'linkedin', 'notes', 'ignore',
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

        $sourceId = $this->resolveSource($this->option('source') ?: 'Direct');
        $typeId = (int) $this->option('type');
        $pipelineId = (int) $this->option('pipeline');
        $stageId = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', $this->option('stage'))
            ->value('id') ?? 1;

        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $skipped = 0;
        $errors = 0;

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Importing ".count($rows)." rows...");
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

                // Idempotency: reuse an existing person by email.
                $person = Person::whereJsonContains('emails', [['value' => $email]])->first();

                if (! $person) {
                    $person = $personRepository->create(array_filter([
                        'name'              => $name,
                        'emails'            => [['value' => $email, 'label' => 'work']],
                        'job_title'         => $title ?: null,
                        'organization_name' => $company ?: null,
                        'user_id'           => $ownerId,
                        'entity_type'       => 'persons',
                    ], fn ($v) => ! is_null($v)));
                }

                // Skip if this person already has a lead (idempotent re-runs).
                if (Lead::where('person_id', $person->id)->exists()) {
                    $skipped++;
                    continue;
                }

                $leadRepository->create([
                    'title'                 => $company ? "{$name} - {$company}" : $name,
                    'description'           => $this->buildNotes($data),
                    'lead_value'            => 0,
                    'status'                => 1,
                    'person_id'             => $person->id,
                    'lead_source_id'        => $sourceId,
                    'lead_type_id'          => $typeId,
                    'lead_pipeline_id'      => $pipelineId,
                    'lead_pipeline_stage_id'=> $stageId,
                    'user_id'               => $ownerId,
                    'entity_type'           => 'leads',
                ]);

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
            '%sDone. Created: %d | Skipped (no email / already imported): %d | Errors: %d',
            $dryRun ? '[DRY RUN] ' : '', $created, $skipped, $errors
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
            ."Headers: ".json_encode(array_values($headers))."\n"
            ."Respond ONLY with a JSON object of {\"header\":\"field\"}.";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post(rtrim($domain, '/').'/chat/completions', [
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You map spreadsheet headers to fields. Reply with JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0,
            ]);

            if ($response->failed()) {
                $this->warn('AI mapping request failed; using fuzzy matching.');

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
            'last_name'  => ['last', 'lastname', 'lname', 'surname', 'familyname'],
            'full_name'  => ['name', 'fullname', 'contact', 'contactname', 'person'],
            'email'      => ['email', 'emails', 'emailaddress', 'mail', 'workemail', 'e'],
            'phone'      => ['phone', 'phonenumber', 'mobile', 'cell', 'tel', 'contactnumber'],
            'job_title'  => ['title', 'jobtitle', 'role', 'position', 'designation'],
            'company'    => ['company', 'organization', 'organisation', 'org', 'employer', 'firm', 'account'],
            'linkedin'   => ['linkedin', 'linkedinurl', 'li', 'profile'],
            'notes'      => ['notes', 'note', 'description', 'comment', 'comments', 'fitscore', 'fit', 'leadsource', 'leadstatus'],
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
     * Build the lead description from linkedin + notes.
     */
    private function buildNotes(array $data): string
    {
        $parts = [];
        if (! empty($data['linkedin'])) {
            $parts[] = 'LinkedIn: '.$data['linkedin'];
        }
        if (! empty($data['notes'])) {
            $parts[] = $data['notes'];
        }

        return trim(implode("\n", $parts));
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
            'name'       => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
