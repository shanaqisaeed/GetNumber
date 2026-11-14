<?php

namespace App\Services\Communication;

use App\Contracts\Communication\EmailServiceInterface;
use App\Models\EmailAlias;
use App\Models\EmailMailbox;
use App\Models\User;
use App\Models\Web3Domain;
use Illuminate\Support\Str;

class EmailService implements EmailServiceInterface
{
    public function createMailbox(
        User $user,
        Web3Domain $domain,
        string $localPart,
        ?string $displayName = null,
        int $quotaMb = 1024
    ): EmailMailbox {
        $cleanLocal = strtolower(trim($localPart));
        $fullAddress = "{$cleanLocal}@{$domain->full_domain}";

        return EmailMailbox::create([
            'user_id' => $user->id,
            'web3_domain_id' => $domain->id,
            'address' => $fullAddress,
            'display_name' => $displayName ?: $user->name,
            'status' => 'active',
            'quota_mb' => $quotaMb,
            'used_mb' => 0,
            'metadata' => [
                'server_host' => 'mail.' . $domain->full_domain,
                'created_by' => $user->id,
            ],
        ]);
    }

    public function createAlias(EmailMailbox $mailbox, string $aliasAddress): EmailAlias
    {
        $cleanAlias = strtolower(trim($aliasAddress));
        if (! str_contains($cleanAlias, '@')) {
            $domainPart = Str::after($mailbox->address, '@');
            $cleanAlias = "{$cleanAlias}@{$domainPart}";
        }

        return EmailAlias::create([
            'mailbox_id' => $mailbox->id,
            'alias_address' => $cleanAlias,
            'is_active' => true,
        ]);
    }

    public function deleteAlias(EmailAlias|int $alias): bool
    {
        $aliasModel = is_int($alias) ? EmailAlias::findOrFail($alias) : $alias;

        return (bool) $aliasModel->delete();
    }

    public function sendDirectEmail(
        string $fromAddress,
        string $toAddress,
        string $subject,
        string $body,
        array $attachments = []
    ): array {
        $trackingId = 'EML-' . Str::upper(Str::random(16));

        // Future hook: connect to SMTP / Decentralized Web3 Mail Node (Mailchain / IPFS)
        return [
            'success' => true,
            'tracking_id' => $trackingId,
            'message' => 'ایمیل با موفقیت ارسال گردید (Mock/Stub).',
        ];
    }

    public function receiveDirectEmail(
        string $toAddress,
        string $fromAddress,
        string $subject,
        string $body
    ): array {
        $cleanTo = strtolower(trim($toAddress));
        $mailbox = EmailMailbox::where('address', $cleanTo)->first();
        if (! $mailbox) {
            $alias = EmailAlias::where('alias_address', $cleanTo)->where('is_active', true)->first();
            $mailbox = $alias?->mailbox;
        }

        $messageId = 'MSG-' . Str::uuid();

        return [
            'received' => $mailbox !== null,
            'mailbox_id' => $mailbox?->id,
            'message_id' => $messageId,
        ];
    }
}

