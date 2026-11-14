<?php

namespace App\Contracts\Communication;

use App\Models\EmailAlias;
use App\Models\EmailMailbox;
use App\Models\User;
use App\Models\Web3Domain;

interface EmailServiceInterface
{
    /**
     * Create or provision a mailbox for a Web3 domain.
     *
     * @param User $user
     * @param Web3Domain $domain
     * @param string $localPart
     * @param string|null $displayName
     * @param int $quotaMb
     * @return EmailMailbox
     */
    public function createMailbox(
        User $user,
        Web3Domain $domain,
        string $localPart,
        ?string $displayName = null,
        int $quotaMb = 1024
    ): EmailMailbox;

    /**
     * Create a sub-alias (unlimited sub-emails) for an existing mailbox.
     *
     * @param EmailMailbox $mailbox
     * @param string $aliasAddress
     * @return EmailAlias
     */
    public function createAlias(EmailMailbox $mailbox, string $aliasAddress): EmailAlias;

    /**
     * Remove an alias from a mailbox.
     *
     * @param EmailAlias|int $alias
     * @return bool
     */
    public function deleteAlias(EmailAlias|int $alias): bool;

    /**
     * Send direct email (Interface Stub for upcoming SMTP/blockchain mail engine).
     *
     * @param string $fromAddress
     * @param string $toAddress
     * @param string $subject
     * @param string $body
     * @param array $attachments
     * @return array{success: bool, tracking_id: string, message: string}
     */
    public function sendDirectEmail(
        string $fromAddress,
        string $toAddress,
        string $subject,
        string $body,
        array $attachments = []
    ): array;

    /**
     * Mock intake / receipt of an email message into a mailbox.
     *
     * @param string $toAddress
     * @param string $fromAddress
     * @param string $subject
     * @param string $body
     * @return array{received: bool, mailbox_id: ?int, message_id: string}
     */
    public function receiveDirectEmail(
        string $toAddress,
        string $fromAddress,
        string $subject,
        string $body
    ): array;
}

