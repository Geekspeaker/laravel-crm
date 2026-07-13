# Geekspeaker CRM — Fork Changelog

Customizations layered on top of the upstream Krayin `2.2` branch for the
Geekspeaker outreach CRM (deployed on Northflank: app service + managed MySQL +
`/app/storage` volume). Running list — newest at top.

> Deploy model: **pushing this repo triggers a Northflank rebuild/redeploy.** The
> container runs `php artisan serve` only (no queue worker, no scheduler).
> Runtime config comes from Northflank env vars, written into `.env` at boot by
> `docker-entrypoint.sh`.

---

## 2026-07-12

### Deployment / entrypoint (`docker-entrypoint.sh`, `Dockerfile`)
- Base image bumped to **PHP 8.3** (upstream deps require `>= 8.3.0`; 8.2 crashed at
  Composer `platform_check.php`).
- Entrypoint **rewrites `.env` from runtime env vars on every boot** so APP_KEY / DB
  creds / URLs always match Northflank and survive redeploys (fixes the empty
  `.env.example` reset that looked like data loss).
- `.env` values written **unquoted** (except `APP_NAME` / `MAIL_FROM_NAME`, which
  have spaces) because the installer's `getEnvAtRuntime()` does a naive `explode('=')`
  and does not strip quotes — a quoted empty `DB_PREFIX=""` was read as literal `""`
  and corrupted every table name.
- `DB_PREFIX` sanitized (strip stray quotes) as a safety net.
- `migrate --force` made **non-fatal** so a migration error can't crash-loop the web
  server (and block shell access). Never uses `migrate:fresh`/`refresh`/`wipe`.
- Recreates the Laravel **storage skeleton** on boot (so a fresh `/app/storage` volume
  doesn't 500 the app).
- Added `view:clear` on boot so Blade overrides apply after redeploy.
- Writes **IMAP_*** vars and sets **`MAIL_RECEIVER_DRIVER=webklex-imap`** (was
  defaulting to `sendgrid`, which can't bulk-process inbound mail).

### `config/database.php`
- MySQL SSL enabled (`MYSQL_ATTR_SSL_CA` + `SSL_VERIFY_SERVER_CERT => false`) because
  Northflank MySQL enforces `require_secure_transport=ON`.

### `app/Providers/AppServiceProvider.php`
- `URL::forceScheme('https')` in production (behind Northflank/Fastly TLS termination)
  to stop mixed-content / login failures.

### Magic AI → OpenAI native (`MagicAIService.php`, `core_config.php`, lang)
- Endpoint no longer hardcoded to OpenRouter; defaults to the OpenAI native API and
  honors a new **API Domain** setting (`general.magic_ai.settings.api_domain`).
- Model dropdown switched to native OpenAI ids (`gpt-4o`, `gpt-4o-mini`, `gpt-4.1`,
  `gpt-4.1-mini`); free-text "Other Model" still accepts any id.
- All 7 locale `app.php` files synced to keep the lang-parity CI test green.

### Custom fields (migrations)
- **Person:** `linkedin`, `x_handle`, `website`, `alt_emails`.
- **Lead:** `fit_score`, plus segment fields — `fund_stage`, `check_size`,
  `thesis_fit` (VC); `affiliate_network`, `category`, `payout_model` (Reward Partner);
  `company_size`, `industry`, `region` (HR/Work-Edu); and `priority` (universal).

### Smart importer (`app/Console/Commands/ImportOutreachLeads.php`)
- `skimify:import-leads {path}` — builds Organization + Person + Lead per CSV row.
- AI header mapping (one OpenAI call per file) with deterministic **fuzzy fallback**.
- Idempotent by email; `--update` refreshes existing records (backfill); `--no-ai`,
  `--dry-run`, `--source`, `--stage`, `--owner` flags.
- Forces `contact_numbers = []` (null 500'd the person/lead view); writes social +
  segment fields to the new attributes instead of dumping into `description`.

### Lead view (`leads/view/attributes.blade.php`, `leads/view/person.blade.php`)
- "About Lead" panel shows **only the segment-relevant custom fields** based on the
  lead's source (VC / Reward Partner / HR-Work-Edu); other segments' fields hidden.
- Person panel on the lead view now surfaces **LinkedIn / Website / X** from the
  contact record.

### Outreach Copilot — thesis-matched opener generator
- New lead attributes `ai_dossier` + `ai_draft` (migration).
- `php artisan skimify:draft-opener` — for each lead, builds a short research dossier,
  a **thesis-fit** line (saved to the `thesis_fit` field), and a punchy, hook-first
  opener connecting Skimify's attention-data thesis to the target's segment angle
  (VC thesis / partner category / HR pain), optionally grounded by a read of the firm's
  website. Saved to `ai_dossier` / `thesis_fit` / `ai_draft` for review — never
  auto-sent. Flags: `--lead`, `--source`, `--stage`, `--limit`, `--force`, `--no-web`.
- **In-dashboard "✨ AI Draft" button** on the lead view (About Lead panel):
  `POST admin/leads/{id}/ai-draft` → `LeadAiController` **dispatches `GenerateLeadOpener`
  to the queue** and returns instantly (the button then auto-refreshes ~30s later).
  Runs off the request thread so the single-threaded server never blocks → no more 503.
  Because it's async it uses website grounding.
- Added a **background `queue:work`** to the entrypoint (database driver) to process
  those jobs (and any future queued work).
- Dropped the `temperature` param from all AI calls (reasoning models only allow the
  default).

### Outreach write API (token-gated)
- `POST /api/outreach/leads/upsert` (idempotent by email → Org+Person+Lead, mirrors the
  importer's field mapping; returns lead_id + created/updated) and
  `POST /api/outreach/touches` (append an activity + optional stage change; idempotent via
  a dedupe hash in `activities.additional`).
- Auth: static bearer token `CRM_API_TOKEN` (Northflank env → `.env`) checked by
  `VerifyCrmApiToken` middleware; rate-limited `throttle:60,1`. Least privilege — only these
  two endpoints, no session/super-admin. Rotate by changing the env var + redeploy.
- Lets the outreach agent log adds + touches programmatically (no MySQL, no markdown).

### PDL enrichment (in-app)
- `php artisan skimify:enrich` + a queued `EnrichLead` job + a **🔎 Enrich** button on the
  lead view. One People Data Labs **Person Enrichment** call (by primary email) fills the
  person (job_title, linkedin, website, alt_emails) AND the company fields the PDL person
  record carries (company_size, industry, region) — so it doesn't burn the scarce Company
  Enrichment credits. Only fills empty fields unless `--force`; rate-limited to stay under
  100/min. Needs `PDL_API_KEY` set on Northflank (written to `.env` by the entrypoint).
- The lead attributes panel now has two buttons — **🔎 Enrich** and **✨ AI Draft** — sharing
  a generic `v-lead-ai-action` Vue component (queued, non-blocking, auto-refresh).

### Email automation — relevant-only inbound + threading + auto-sync
- **Reply-To = From** (`Email.php` Mailable): outgoing mail now replies to the real
  sender mailbox instead of the `@MAIL_DOMAIN` tracking address — replies land in the
  polled inbox, still thread via the existing `Message-ID`/`References` headers, and
  Reply-To matches From (fixes spam-foldering). Pair with `MAIL_DOMAIN=geekspeaker.com`.
- **Relevance filter** (`WebklexImapEmailProcessor`): only imports a message if it
  threads to an email we sent OR is from a known person/lead — the CRM captures replies
  from people we're working, not the whole mailbox. Fetch window is now
  `INBOUND_FETCH_DAYS` (default 14) instead of a hardcoded 10.
- **Auto-sync** (`docker-entrypoint.sh`): runs `php artisan schedule:work` in the
  background so the existing `inbound-emails:process` schedule (every 5 min) actually
  fires. Incoming replies now appear on the matching lead automatically.

---

## Open / planned (not yet done)

- **SMTP port:** `MAIL_PORT` must be `465` (ssl) or `587` (tls) — a `456` typo caused a
  send hang / 503. (Env-var fix on Northflank.)
- **Queue outgoing mail:** a background `queue:work` now runs, so outgoing mail could be
  queued (currently still sends synchronously on `serve`). Route the mailer to the queue
  to stop slow SMTP sends briefly blocking the web server.
- **Alt-emails matching:** the inbound relevance filter matches a person's primary
  emails only, not the `alt_emails` field — extend if replies come from alternates.
- **Edit form scoping:** the lead *edit* form still shows all segment fields (only the
  read view is scoped) — optional to scope it too.
