# Geekspeaker CRM — Fork Changelog

Customizations layered on top of the upstream Krayin `2.2` branch for the
Geekspeaker outreach CRM (deployed on Northflank: app service + managed MySQL +
`/app/storage` volume). Running list — newest at top.

> Deploy model: **pushing this repo triggers a Northflank rebuild/redeploy.** The
> container runs `php artisan serve` only (no queue worker, no scheduler).
> Runtime config comes from Northflank env vars, written into `.env` at boot by
> `docker-entrypoint.sh`.

---

## 2026-07-18

### "Replied / Engaged" stage (all pipelines) + Publisher `contacted` retired
- `2026_07_18_180000_add_replied_stage.php`: inserts a `replied` ("Replied / Engaged") stage
  immediately after Cold Email / Call in all 4 segment pipelines. Fills the real funnel gap —
  they responded, nothing booked yet — so a reply no longer has to land on a sourcing stage
  (VC "Warm Intro") or jump straight to First Call. Idempotent.
- Retires the Publisher pipeline's redundant `contacted` stage (Cold Email/Call +
  Replied/Engaged cover it). **Leads on it are moved to Cold Email/Call first** — the stage FK
  is ON DELETE SET NULL, so deleting without migrating would blank their stage.
- Stage-on-reply now targets the `replied` stage explicitly, falling back to "next stage by
  order" for pipelines without it (e.g. the default pipeline).

### "Champion Engaged" stage added to the Brand / Offer-wall pipeline
- `2026_07_18_170000_add_champion_stage_to_brand.php`: inserts a `champion` ("Champion
  Engaged") stage right before "Terms" (Prospect → Cold Email/Call → Champion Engaged →
  Terms → …). Anchored on the `terms` position; idempotent.

### "Cold Email / Call" stage added to every segment pipeline
- `2026_07_18_160000_add_cold_outreach_stage.php`: inserts a `cold_outreach` ("Cold Email /
  Call") stage at position 2 in each of the 4 segment pipelines (after Sourced/Identified/
  Prospect), shifting later stages up. Idempotent (skips a pipeline that already has it).
- Stage-on-reply updated: a reply now advances a lead at/before the cold-outreach stage
  (Sourced or Cold Email/Call) straight to the next "engaged" stage — so cold outreach is
  tracked but a reply still jumps the lead forward. Falls back to first→next where a
  pipeline has no cold_outreach stage.

### GTM upgrade Phase 3 (partial) — segment fields + tags
Spec: `.kiro/specs/crm-gtm-upgrade`.
- **New attributes** (`2026_07_18_140000_add_segment_v2_attributes.php`): `warm_intro_path`
  (VC); `seats_or_students`, `edu_semester`, `ferpa_flag` (HR/EDU); `domain`, `feed_type`,
  `monthly_traffic`, `outbound_clicks_sent` (Publisher); `audience_fit`, `compliance_flag`
  (Brand). Idempotent; booleans for the two flags.
- **Publisher segment** added to the source-scoped read + edit views (source contains
  "publish"); each segment now shows its full signature field set.
- **Write API + importer** field maps extended with the new codes (importer canonical list
  + fuzzy-mappable).
- **Starter tags** seeded (`2026_07_18_150000_seed_starter_tags.php`): warm-intro, inbound,
  met-at-event, portfolio-fit, champion, decision-maker (idempotent by name; owner = first
  user).
- **Stale-lead nudge** (`skimify:flag-stale-leads`, scheduled daily 09:00): tags open leads
  (not won/lost) with no activity for N days (default 14) as `stale` so they resurface.
  Idempotent; time-based so it's a scheduled command, not a workflow. `--days`, `--dry-run`.
- **Stage-on-reply** (`WebklexImapEmailProcessor::advanceLeadOnReply`): an inbound reply
  from a known contact advances their lead off the first pipeline stage to the next
  ("engaged") stage. Inbox-only, idempotent (only moves a lead still on its first stage),
  best-effort (never breaks inbound sync).
- Still pending in Phase 3: WebForm inbound capture (3.5/3.6). Auto-assign (3.9) is already
  covered (API/importer/UI creation all set an owner) and pairs with WebForm.

### GTM upgrade Phase 2 (routing) — per-segment pipelines + source routing
Spec: `.kiro/specs/crm-gtm-upgrade`.
- **New migration** `2026_07_18_120000_seed_segment_pipelines.php`: seeds 4 GTM pipelines
  (VC / Investors, HR / Work-Edu, Publishers, Brand / Offer-wall) + their segment-specific
  stages, and the `Publisher` lead source. Idempotent (`insertOrIgnore` on unique
  name / code+pipeline) — safe under boot `migrate --force`; default pipeline 1 + existing
  leads untouched.
- **`app/Support/PipelineResolver.php`**: `source → {pipeline, first stage}` (unknown →
  default pipeline 1 / `new`). Reused by the write API + importer (both previously hardcoded
  pipeline 1).
- **`OutreachApiController`**: upsert routes by `source`; explicit `stage`/`set_stage`
  applies only if the code exists on the resolved / lead's pipeline (no more generic-only
  gate). Update path resolves stage against the lead's own pipeline (never moves pipeline
  on upsert).
- **`ImportOutreachLeads`**: routes by `--source` via the resolver.
- **`skimify:reassign-pipelines`** (`--dry-run`): backfills existing leads onto their
  segment pipeline; preserves won/lost; non-destructive.
- Steering `crm-outreach-api.md` updated with the per-segment stage codes (generic codes
  now apply to the default pipeline only).
- **Collateral seed** (`2026_07_18_130000_seed_collateral_products.php`): seeds the send
  assets per segment (VC deck; Publisher deck + Publisher Value report; HR one-pager; EDU
  deck; Offer-wall one-pager) as products (idempotent by sku), attachable to a lead via the
  existing lead↔product relation.
- **Proposals** framing: the Quotes module is relabeled to "Proposals" (Phase 1); proposals
  are built per-lead from the Collateral line items + free-form terms. Rich per-segment
  quote templates deferred (not needed yet).

### GTM upgrade Phase 1 — declutter (`menu.php`, admin `en` lang)
Spec: `.kiro/specs/crm-gtm-upgrade` (see `spec-log.md`).
- **Hid Warehouse** from the nav (removed the `settings.inventory` +
  `settings.inventory.warehouse` menu entries). Module + tables remain installed —
  reversible; nav-only change.
- **Relabeled Products → "Collateral"** and **Quotes → "Proposals"** by changing the
  values of the top-level `layouts` nav keys the menu points at (`layouts.products/quotes`
  + singular `product/quote`). No keys added/removed → lang-parity untouched. Relabeled
  `en` only (the container's active locale); other locales keep translated values (parity
  is key-based).
- Relabeled the lead **create/edit "Products" section** and the lead **view tabs**
  (Products→Collateral, Quotes→Proposals) in `en`.
- No schema changes, no core logic changes. Sets up Phase 2 (per-segment pipelines +
  Collateral/Proposals seed).

---

## 2026-07-13

### Outreach write API — alternate-key identity (`OutreachApiController`)
- `/leads/upsert` no longer hard-requires an email. Identity resolves in priority
  order: valid **`email`** → **`linkedin`** → **`name`(+first/last) + `company`**. This
  lets the outreach agent log **LinkedIn-only contacts** (e.g. Mark Kirkham) without
  fabricating a placeholder email. If none of the three is supplied it returns
  `422 {"error":"missing_identifier"}` (was `invalid_email`).
- LinkedIn matching is normalized (ignores scheme / `www.` / trailing slash / case) and
  reads the `linkedin` EAV attribute from `attribute_values` (narrow by vanity slug,
  then exact normalized compare). name+company matches `persons.name`
  (case-insensitive) within the org.
- Email-less contacts are created with `emails => []`; a later upsert that includes an
  email **backfills** it (never wipes existing emails/org). Response now includes
  `matched_by` (`email|linkedin|name_company|created`).
- `/touches` lead resolution reuses the same resolver, so touches can be keyed by
  `lead_id` **or** `email` **or** `linkedin` **or** `name`+`company`.
- Shared `resolvePerson()` / `findPersonByLinkedin()` / `findPersonByNameCompany()`
  helpers; upsert + touches now go through one identity path. Contract doc updated
  (`.kiro/steering/crm-outreach-api.md`).

### Magic AI — NVIDIA NIM provider option (`core_config.php`, admin lang ×7)
- Added NVIDIA NIM models to the Magic AI **Model** dropdown (OpenAI-compatible):
  `meta/llama-3.3-70b-instruct`, `meta/llama-3.1-405b-instruct`,
  `nvidia/llama-3.1-nemotron-70b-instruct`, `deepseek-ai/deepseek-r1`. Title keys added
  to all 7 admin locales (lang-parity CI) and the `api-domain` hint now names the NVIDIA
  endpoint.
- No code change needed to *use* it — the importer / draft-opener / file-extract already
  honor the **API Domain** + **API Key** + model id. To switch: set API Domain =
  `https://integrate.api.nvidia.com/v1` + an `nvapi-` key in Configuration → Magic AI.
  Documented in `business/outreach/crm/krayin-northflank-deploy.md` §6.5.

### Lead edit form scoped by source (`leads/edit.blade.php`)
- The lead **edit** form now shows only the segment-relevant custom fields (VC / Reward
  Partner / HR-Work-Edu), matching the read view (`leads/view/attributes.blade.php`).
  Other segments' fields are hidden; hidden text fields keep their stored values on save
  (they're simply absent from the POST, and Krayin's attribute save skips absent text
  codes). Same source→segment detection as the read view.

### `thesis_fit` is universal + relabeled per segment (lead view + edit blades)
- The Copilot generates `thesis_fit` for **every** segment, but the segment scoping had
  it filed under VC-only fields, so it was hidden on non-VC leads (e.g. an HR lead where
  a fit line had been generated). It's now treated as a universal field — visible on all
  leads in both the read view and the edit form.
- Because "Thesis Fit" is VC jargon, the **label** is relabeled to **"Skimify Fit"** for
  non-VC segments (partner / HR-Work-Edu); VC leads keep "Thesis Fit". Label override is
  in-memory only — the stored attribute value/code is unchanged.

### Inbound relevance filter matches `alt_emails` (`WebklexImapEmailProcessor`)
- The `$fromKnown` check previously matched only a person's primary `emails` JSON. It now
  also matches the sender against the `alt_emails` Person attribute (EAV text, stored as a
  "; "-delimited string) via `isKnownAltEmail()` — narrow by LIKE, confirm on an exact
  token to avoid substring false-positives. So replies from a contact's secondary address
  are now captured onto the lead.

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
- ~~**Alt-emails matching:** the inbound relevance filter matches a person's primary
  emails only.~~ Done 2026-07-13 (matches `alt_emails` too).
- ~~**Edit form scoping:** the lead *edit* form still shows all segment fields.~~ Done
  2026-07-13 (edit form scoped by source, like the read view).
