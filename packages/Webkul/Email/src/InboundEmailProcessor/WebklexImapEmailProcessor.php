<?php

namespace Webkul\Email\InboundEmailProcessor;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;
use Webklex\IMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Message;
use Webkul\Contact\Models\Person;
use Webkul\Email\Enums\SupportedFolderEnum;
use Webkul\Email\InboundEmailProcessor\Contracts\InboundEmailProcessor;
use Webkul\Email\Repositories\AttachmentRepository;
use Webkul\Email\Repositories\EmailRepository;

class WebklexImapEmailProcessor implements InboundEmailProcessor
{
    /**
     * The IMAP client instance.
     */
    protected $client;

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected EmailRepository $emailRepository,
        protected AttachmentRepository $attachmentRepository
    ) {
        $this->client = Client::make($this->getDefaultConfigs());

        $this->client->connect();

        if (! $this->client->isConnected()) {
            throw new \Exception('Failed to connect to the mail server.');
        }
    }

    /**
     * Close the connection.
     */
    public function __destruct()
    {
        $this->client->disconnect();
    }

    /**
     * Process messages from all folders.
     */
    public function processMessagesFromAllFolders()
    {
        try {
            $rootFolders = $this->client->getFolders();

            $this->processMessagesFromLeafFolders($rootFolders);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Process the inbound email.
     *
     * @param  ?Message  $message
     */
    public function processMessage($message = null): void
    {
        $attributes = $message->getAttributes();

        $messageId = $attributes['message_id']->first();

        $email = $this->emailRepository->findOneByField('message_id', $messageId);

        if ($email) {
            return;
        }

        $replyToEmails = $this->getEmailsByAttributeCode($attributes, 'to');

        foreach ($replyToEmails as $to) {
            if ($email = $this->emailRepository->findOneWhere(['message_id' => $to])) {
                break;
            }
        }

        if (! isset($email) && isset($attributes['in_reply_to'])) {
            $inReplyTo = $attributes['in_reply_to']->first();

            $email = $this->emailRepository->findOneWhere(['message_id' => $inReplyTo]);

            if (! $email) {
                $email = $this->emailRepository->findOneWhere([['reference_ids', 'like',  '%'.$inReplyTo.'%']]);
            }
        }

        $references = [$messageId];

        if (! isset($email) && isset($attributes['references'])) {
            array_push($references, ...$attributes['references']->all());

            foreach ($references as $reference) {
                if ($email = $this->emailRepository->findOneWhere([['reference_ids', 'like', '%'.$reference.'%']])) {
                    break;
                }
            }
        }

        /**
         * Relevance filter: only import a message if it threads to an email we sent
         * (a reply from someone we're working) OR it comes from a known person/lead —
         * matched on their primary emails OR their `alt_emails` attribute (people often
         * reply from a secondary address). Everything else is skipped so the CRM stays
         * focused on outreach instead of mirroring the whole mailbox.
         */
        $fromEmail = optional($attributes['from']->first())->mail;

        $fromKnown = $fromEmail && (
            Person::query()
                ->whereJsonContains('emails', [['value' => $fromEmail]])
                ->orWhereJsonContains('emails', [['value' => strtolower($fromEmail)]])
                ->exists()
            || $this->isKnownAltEmail($fromEmail)
        );

        if (empty($email) && ! $fromKnown) {
            return;
        }

        /**
         * Maps the folder name to the supported folder in our application.
         *
         * To Do: Review this.
         */
        $folderName = match ($message->getFolder()->name) {
            'INBOX' => SupportedFolderEnum::INBOX->value,
            'Important' => SupportedFolderEnum::IMPORTANT->value,
            'Starred' => SupportedFolderEnum::STARRED->value,
            'Drafts' => SupportedFolderEnum::DRAFT->value,
            'Sent Mail' => SupportedFolderEnum::SENT->value,
            'Trash' => SupportedFolderEnum::TRASH->value,
            default => '',
        };

        $parentEmail = null;

        if ($email) {
            $parentEmail = $this->emailRepository->update([
                'folders' => array_unique(array_merge($email->folders, [$folderName])),
                'reference_ids' => array_merge($email->reference_ids ?? [], [$references]),
            ], $email->id);
        }

        $email = $this->emailRepository->create([
            'from' => $attributes['from']->first()->mail,
            'subject' => $attributes['subject']->first(),
            'name' => $attributes['from']->first()->personal,
            'reply' => $message->bodies['html'] ?? $message->bodies['text'],
            'is_read' => (int) $message->flags()->has('seen'),
            'folders' => [$folderName],
            'reply_to' => $this->getEmailsByAttributeCode($attributes, 'to'),
            'cc' => $this->getEmailsByAttributeCode($attributes, 'cc'),
            'bcc' => $this->getEmailsByAttributeCode($attributes, 'bcc'),
            'source' => 'email',
            'user_type' => 'person',
            'unique_id' => $messageId,
            'message_id' => $messageId,
            'reference_ids' => $references,
            'created_at' => $this->convertToDesiredTimezone($message->date->toDate()),
            'parent_id' => $parentEmail?->id,
        ]);

        if ($message->hasAttachments()) {
            $this->attachmentRepository->uploadAttachments($email, [
                'source' => 'email',
                'attachments' => $message->getAttachments(),
            ]);
        }

        // GTM upgrade Phase 3 (3.8): an inbound reply from a known contact advances
        // their lead off the first pipeline stage. Inbox-only; best-effort.
        if ($fromKnown && $folderName === SupportedFolderEnum::INBOX->value) {
            $this->advanceLeadOnReply($fromEmail);
        }
    }

    /**
     * When a known contact replies (inbound), advance their lead from the first
     * pipeline stage to the next ("engaged") stage. Only moves a lead still on its
     * pipeline's first stage — so it's idempotent, never moves a lead backward, and
     * leaves already-progressed leads alone. Best-effort: never breaks inbound sync.
     */
    protected function advanceLeadOnReply(?string $fromEmail): void
    {
        try {
            $email = trim((string) $fromEmail);
            if ($email === '') {
                return;
            }

            $person = Person::query()
                ->whereJsonContains('emails', [['value' => $email]])
                ->orWhereJsonContains('emails', [['value' => strtolower($email)]])
                ->first();

            if (! $person) {
                return;
            }

            $leads = DB::table('leads')
                ->where('person_id', $person->id)
                ->get(['id', 'lead_pipeline_id', 'lead_pipeline_stage_id']);

            foreach ($leads as $lead) {
                $currentOrder = DB::table('lead_pipeline_stages')
                    ->where('id', $lead->lead_pipeline_stage_id)
                    ->value('sort_order');

                $firstOrder = (int) DB::table('lead_pipeline_stages')
                    ->where('lead_pipeline_id', $lead->lead_pipeline_id)
                    ->min('sort_order');

                // "Engaged threshold" = the cold-outreach stage if the pipeline has one,
                // else the first stage. A reply advances a lead at/before this threshold
                // (Sourced or Cold Email/Call) to the next ("engaged") stage — and leaves
                // already-engaged leads alone. Idempotent, never backward.
                $coldOrder = DB::table('lead_pipeline_stages')
                    ->where('lead_pipeline_id', $lead->lead_pipeline_id)
                    ->where('code', 'cold_outreach')
                    ->value('sort_order');
                $threshold = $coldOrder !== null ? (int) $coldOrder : $firstOrder;

                if ($currentOrder === null || (int) $currentOrder > $threshold) {
                    continue;
                }

                $nextId = DB::table('lead_pipeline_stages')
                    ->where('lead_pipeline_id', $lead->lead_pipeline_id)
                    ->where('sort_order', '>', $threshold)
                    ->orderBy('sort_order')
                    ->value('id');

                if ($nextId) {
                    DB::table('leads')->where('id', $lead->id)->update([
                        'lead_pipeline_stage_id' => $nextId,
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('advanceLeadOnReply failed: '.$e->getMessage());
        }
    }

    /**
     * Process the messages from all folders.
     *
     * @param  FolderCollection  $rootFoldersCollection
     */
    protected function processMessagesFromLeafFolders($rootFoldersCollection = null): void
    {
        $rootFoldersCollection->each(function ($folder) {
            if (! $folder->children->isEmpty()) {
                $this->processMessagesFromLeafFolders($folder->children);

                return;
            }

            if (in_array($folder->name, ['All Mail'])) {
                return;
            }

            return $folder->query()->since(now()->subDays((int) (env('INBOUND_FETCH_DAYS', 14))))->get()->each(function ($message) {
                $this->processMessage($message);
            });
        });
    }

    /**
     * Get the emails by the attribute code.
     */
    protected function getEmailsByAttributeCode(array $attributes, string $attributeCode): array
    {
        $emails = [];

        if (isset($attributes[$attributeCode])) {
            $emails = collect($attributes[$attributeCode]->all())->map(fn ($attribute) => $attribute->mail)->toArray();
        }

        return $emails;
    }

    /**
     * Convert the date to the desired timezone.
     *
     * @param  Carbon  $carbonDate
     * @param  ?string  $targetTimezone
     */
    protected function convertToDesiredTimezone($carbonDate, $targetTimezone = null)
    {
        $targetTimezone = $targetTimezone ?: config('app.timezone');

        return $carbonDate->clone()->setTimezone($targetTimezone);
    }

    /**
     * Match the sender against the `alt_emails` Person attribute. `alt_emails` is an
     * EAV text attribute stored as a delimited string (e.g. "a@x.com; b@y.com") in
     * attribute_values, so we narrow by a LIKE and confirm on an exact token to avoid
     * substring false-positives. Primary emails are checked separately on persons.emails.
     */
    protected function isKnownAltEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        $attributeId = DB::table('attributes')
            ->where('entity_type', 'persons')
            ->where('code', 'alt_emails')
            ->value('id');

        if (! $attributeId) {
            return false;
        }

        $values = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('attribute_id', $attributeId)
            ->whereRaw('LOWER(text_value) LIKE ?', ['%'.$email.'%'])
            ->pluck('text_value');

        foreach ($values as $value) {
            $tokens = preg_split('/[;,\s]+/', strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY);

            if (in_array($email, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default configurations.
     */
    protected function getDefaultConfigs(): array
    {
        $defaultConfig = config('imap.accounts.default');

        $defaultConfig['host'] = core()->getConfigData('email.imap.account.host') ?: $defaultConfig['host'];

        $defaultConfig['port'] = core()->getConfigData('email.imap.account.port') ?: $defaultConfig['port'];

        $defaultConfig['encryption'] = core()->getConfigData('email.imap.account.encryption') ?: $defaultConfig['encryption'];

        $defaultConfig['validate_cert'] = (bool) core()->getConfigData('email.imap.account.validate_cert');

        $defaultConfig['username'] = core()->getConfigData('email.imap.account.username') ?: $defaultConfig['username'];

        $defaultConfig['password'] = core()->getConfigData('email.imap.account.password') ?: $defaultConfig['password'];

        return $defaultConfig;
    }
}
