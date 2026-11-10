<?php

namespace App\Services;

use App\Contracts\LedgerServiceInterface;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LedgerService implements LedgerServiceInterface
{
    protected const DECIMAL_SCALE = 4;

    public function recordJournal(
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $actor = null,
        array $metadata = [],
        string $currency = 'COIN'
    ): JournalEntry {
        if (empty($lines)) {
            throw new InvalidArgumentException(__('services.ledger.two_lines_required'));
        }

        return DB::transaction(function () use ($description, $lines, $referenceType, $referenceId, $actor, $metadata, $currency) {
            // Check idempotency if referenceId is provided
            if (! empty($referenceId) && ! empty($referenceType)) {
                $existing = JournalEntry::where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $entryNumber = 'JRN-' . date('Ymd') . '-' . strtoupper(Str::random(10));
            $totalDebit = '0.0000';
            $totalCredit = '0.0000';
            $preparedEntries = [];

            foreach ($lines as $line) {
                $accountCode = $line['account_code'] ?? null;
                if (! $accountCode) {
                    throw new InvalidArgumentException(__('services.ledger.account_code_required'));
                }

                $account = LedgerAccount::findByCode($accountCode);
                if (! $account) {
                    throw new InvalidArgumentException(__('services.ledger.account_not_found', ['code' => $accountCode]));
                }

                $rawDebit = (string) ($line['debit'] ?? '0.0000');
                $rawCredit = (string) ($line['credit'] ?? '0.0000');

                $debit = number_format((float) $rawDebit, self::DECIMAL_SCALE, '.', '');
                $credit = number_format((float) $rawCredit, self::DECIMAL_SCALE, '.', '');

                $totalDebit = bcadd($totalDebit, $debit, self::DECIMAL_SCALE);
                $totalCredit = bcadd($totalCredit, $credit, self::DECIMAL_SCALE);

                $preparedEntries[] = [
                    'ledger_account_id' => $account->id,
                    'user_id' => $line['user_id'] ?? null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $line['description'] ?? null,
                ];
            }

            // Invariant Verification: Total Debit MUST strictly equal Total Credit
            if (bccomp($totalDebit, $totalCredit, self::DECIMAL_SCALE) !== 0) {
                throw new UnbalancedJournalEntryException(
                    totalDebit: $totalDebit,
                    totalCredit: $totalCredit,
                    entryNumber: $entryNumber
                );
            }

            if (bccomp($totalDebit, '0.0000', self::DECIMAL_SCALE) <= 0) {
                throw new InvalidArgumentException(__('services.ledger.total_must_be_positive'));
            }

            $journal = JournalEntry::create([
                'entry_number' => $entryNumber,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'currency' => $currency,
                'description' => $description,
                'posted_at' => now(),
                'created_by' => $actor?->id,
                'metadata' => array_merge($metadata, [
                    'total_amount' => $totalDebit,
                ]),
            ]);

            foreach ($preparedEntries as $entryData) {
                $journal->entries()->create($entryData);
            }

            return $journal;
        });
    }

    public function recordDeposit(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit Platform Treasury (Asset increases)
            [
                'account_code' => '1010_PLATFORM_TREASURY',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => null,
                'description' => __('services.ledger.desc_deposit', ['user_id' => $user->id]),
            ],
            // Credit User Wallet Liability (User account liability increases)
            [
                'account_code' => '2010_USER_WALLETS',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => $user->id,
                'description' => $description,
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'CryptoDeposit',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordNumberPurchase(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit User Wallet Liability (User account liability decreases)
            [
                'account_code' => '2010_USER_WALLETS',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => $user->id,
                'description' => $description,
            ],
            // Credit Number Revenue (Operating Revenue increases)
            [
                'account_code' => '4010_NUMBER_REVENUE',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => null,
                'description' => __('services.ledger.desc_number_sale', ['user_id' => $user->id]),
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'NumberPurchase',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordRefund(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit Number Revenue / Refund Expense
            [
                'account_code' => '4010_NUMBER_REVENUE',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => null,
                'description' => __('services.ledger.desc_number_sale_refund', ['user_id' => $user->id]),
            ],
            // Credit User Wallet Liability (User account liability increases)
            [
                'account_code' => '2010_USER_WALLETS',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => $user->id,
                'description' => $description,
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'NumberRefund',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordHold(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit User Wallet Liability (User available balance decreases)
            [
                'account_code' => '2010_USER_WALLETS',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => $user->id,
                'description' => $description,
            ],
            // Credit Funds On Hold Liability (Escrow/held funds increases)
            [
                'account_code' => '2020_FUNDS_ON_HOLD',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => $user->id,
                'description' => __('services.ledger.desc_org_request_block', ['user_id' => $user->id]),
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'NumberHold',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordHoldFinalize(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit Funds On Hold Liability (Escrow released to revenue)
            [
                'account_code' => '2020_FUNDS_ON_HOLD',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => $user->id,
                'description' => __('services.ledger.desc_org_request_settle', ['user_id' => $user->id]),
            ],
            // Credit Number Revenue (Operating Revenue increases)
            [
                'account_code' => '4010_NUMBER_REVENUE',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => null,
                'description' => __('services.ledger.desc_org_request_sale', ['user_id' => $user->id]),
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'NumberHoldFinalize',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordHoldRelease(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        $lines = [
            // Debit Funds On Hold Liability (Escrow decreased)
            [
                'account_code' => '2020_FUNDS_ON_HOLD',
                'debit' => $cleanAmount,
                'credit' => '0.0000',
                'user_id' => $user->id,
                'description' => __('services.ledger.desc_org_request_release', ['user_id' => $user->id]),
            ],
            // Credit User Wallet Liability (Returned to user active wallet balance)
            [
                'account_code' => '2010_USER_WALLETS',
                'debit' => '0.0000',
                'credit' => $cleanAmount,
                'user_id' => $user->id,
                'description' => $description,
            ],
        ];

        return $this->recordJournal(
            description: $description,
            lines: $lines,
            referenceType: 'NumberHoldRelease',
            referenceId: $referenceId,
            actor: $user,
            metadata: $metadata
        );
    }

    public function recordAdminAdjustment(
        User $user,
        string $amount,
        bool $isCredit,
        User $admin,
        string $reason,
        string $referenceId
    ): JournalEntry {
        $cleanAmount = number_format((float) $amount, self::DECIMAL_SCALE, '.', '');

        if ($isCredit) {
            // Admin credits user: Debit Suspense Account, Credit User Wallet
            $lines = [
                [
                    'account_code' => '9010_SUSPENSE',
                    'debit' => $cleanAmount,
                    'credit' => '0.0000',
                    'user_id' => null,
                    'description' => __('services.ledger.desc_manual_credit', ['admin' => $admin->name, 'reason' => $reason]),
                ],
                [
                    'account_code' => '2010_USER_WALLETS',
                    'debit' => '0.0000',
                    'credit' => $cleanAmount,
                    'user_id' => $user->id,
                    'description' => __('services.ledger.desc_manual_credit_simple', ['reason' => $reason]),
                ],
            ];
        } else {
            // Admin debits user: Debit User Wallet, Credit Suspense Account
            $lines = [
                [
                    'account_code' => '2010_USER_WALLETS',
                    'debit' => $cleanAmount,
                    'credit' => '0.0000',
                    'user_id' => $user->id,
                    'description' => __('services.ledger.desc_manual_debit_simple', ['reason' => $reason]),
                ],
                [
                    'account_code' => '9010_SUSPENSE',
                    'debit' => '0.0000',
                    'credit' => $cleanAmount,
                    'user_id' => null,
                    'description' => __('services.ledger.desc_manual_debit', ['admin' => $admin->name, 'reason' => $reason]),
                ],
            ];
        }

        return $this->recordJournal(
            description: __('services.ledger.desc_manual_adjustment', ['reason' => $reason]),
            lines: $lines,
            referenceType: 'AdminAdjustment',
            referenceId: $referenceId,
            actor: $admin,
            metadata: [
                'target_user_id' => $user->id,
                'admin_id' => $admin->id,
                'reason' => $reason,
                'is_credit' => $isCredit,
            ]
        );
    }

    public function calculateUserBalance(User $user): string
    {
        $walletAccount = LedgerAccount::findByCode('2010_USER_WALLETS');
        if (! $walletAccount) {
            return '0.0000';
        }

        $credits = LedgerEntry::where('ledger_account_id', $walletAccount->id)
            ->where('user_id', $user->id)
            ->sum('credit');

        $debits = LedgerEntry::where('ledger_account_id', $walletAccount->id)
            ->where('user_id', $user->id)
            ->sum('debit');

        $balance = bcsub((string) $credits, (string) $debits, self::DECIMAL_SCALE);

        return number_format((float) $balance, self::DECIMAL_SCALE, '.', '');
    }

    public function checkGlobalBalance(): array
    {
        $totalDebit = (string) LedgerEntry::sum('debit');
        $totalCredit = (string) LedgerEntry::sum('credit');

        $totalDebitClean = number_format((float) $totalDebit, self::DECIMAL_SCALE, '.', '');
        $totalCreditClean = number_format((float) $totalCredit, self::DECIMAL_SCALE, '.', '');

        $isBalanced = bccomp($totalDebitClean, $totalCreditClean, self::DECIMAL_SCALE) === 0;

        return [
            'is_balanced' => $isBalanced,
            'total_debit' => $totalDebitClean,
            'total_credit' => $totalCreditClean,
            'difference' => bcsub($totalDebitClean, $totalCreditClean, self::DECIMAL_SCALE),
            'journal_count' => JournalEntry::count(),
            'entry_count' => LedgerEntry::count(),
        ];
    }
}