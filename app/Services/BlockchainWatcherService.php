<?php

namespace App\Services;

use App\Contracts\BlockchainWatcherServiceInterface;
use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use App\Services\Gateways\PaymentGatewayManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlockchainWatcherService implements BlockchainWatcherServiceInterface
{
    public function __construct(
        protected PaymentServiceInterface $paymentService,
        protected PaymentGatewayManager $gatewayManager
    ) {}

    public function scanAndMatchIncomingDeposits(int $limit = 50): array
    {
        $stats = [
            'scanned_gateways' => 0,
            'incoming_transactions_detected' => 0,
            'auto_matched_deposits' => 0,
            'completed' => 0,
            'confirming' => 0,
            'errors' => 0,
        ];

        // 1. Resolve active gateways
        $activeGateways = PaymentGateway::where('is_active', true)->get();
        if ($activeGateways->isEmpty()) {
            return $stats;
        }

        foreach ($activeGateways as $gatewayModel) {
            $stats['scanned_gateways']++;
            $driver = $this->gatewayManager->forModel($gatewayModel);

            // Fetch pending deposits waiting for transfer for this gateway
            $pendingDeposits = CryptoDeposit::where('gateway_driver', $gatewayModel->driver)
                ->where('status', PaymentStatus::PendingTransfer)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->limit($limit)
                ->get();

            if ($pendingDeposits->isEmpty()) {
                continue;
            }

            try {
                // Fetch recent on-chain deposits from the gateway
                $recentTransfers = $this->fetchRecentGatewayTransfers($gatewayModel, $driver);

                foreach ($recentTransfers as $transfer) {
                    $stats['incoming_transactions_detected']++;

                    $txHash = trim($transfer['tx_hash'] ?? '');
                    $amount = (string) ($transfer['amount'] ?? '0.0000');
                    $toAddress = trim($transfer['to_address'] ?? '');
                    $timestamp = $transfer['timestamp'] ?? null;

                    if (empty($txHash) || empty($toAddress)) {
                        continue;
                    }

                    // Check if this txHash is already claimed by any deposit
                    $alreadyClaimed = CryptoDeposit::where('tx_hash', $txHash)
                        ->where('status', '!=', PaymentStatus::Failed->value)
                        ->exists();

                    if ($alreadyClaimed) {
                        continue;
                    }

                    // Attempt matching with pending deposits
                    foreach ($pendingDeposits as $deposit) {
                        if ($deposit->isCompleted() || $deposit->tx_hash) {
                            continue;
                        }

                        // Match destination address
                        if (strcasecmp($deposit->deposit_address, $toAddress) !== 0) {
                            continue;
                        }

                        // Match amount (allow slight precision match)
                        if (bccomp((string) $deposit->requested_amount, $amount, 4) !== 0) {
                            continue;
                        }

                        // Match timestamp: transaction must not be older than deposit creation minus 10 minutes
                        if ($timestamp) {
                            $txDate = is_numeric($timestamp)
                                ? Carbon::createFromTimestampMs((int) $timestamp)
                                : Carbon::parse($timestamp);

                            if ($txDate->lt($deposit->created_at->subMinutes(10))) {
                                continue;
                            }
                        }

                        // Match found! Auto-associate and verify
                        Log::info("BlockchainWatcher: Auto-matched incoming TX {$txHash} to Deposit #{$deposit->id}");

                        $deposit->update([
                            'tx_hash' => $txHash,
                            'status' => PaymentStatus::TxSubmitted,
                            'attempts' => 0,
                            'next_check_at' => now(),
                        ]);

                        $verification = $this->paymentService->verifyDeposit($deposit->fresh());
                        $stats['auto_matched_deposits']++;

                        AuditLog::create([
                            'user_id' => $deposit->user_id,
                            'action' => 'payment.blockchain_watcher.auto_match',
                            'entity_type' => CryptoDeposit::class,
                            'entity_id' => $deposit->id,
                            'payload' => [
                                'tx_hash' => $txHash,
                                'amount' => $amount,
                                'gateway' => $gatewayModel->driver,
                                'status' => $verification->status,
                            ],
                            'ip_address' => '127.0.0.1',
                        ]);

                        if ($verification->isVerified) {
                            $stats['completed']++;
                        } else {
                            $stats['confirming']++;
                        }

                        break; // matched this transfer
                    }
                }
            } catch (\Throwable $e) {
                Log::error("BlockchainWatcher error scanning gateway {$gatewayModel->driver}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    public function progressPendingConfirmations(int $limit = 50): array
    {
        return $this->paymentService->processPendingDeposits($limit);
    }

    /**
     * Helper to fetch recent on-chain transfers from gateway or explorer.
     */
    protected function fetchRecentGatewayTransfers(PaymentGateway $gatewayModel, $driver): array
    {
        // 1. If driver natively supports getRecentDeposits (e.g. NobitexGateway, MockPaymentGateway)
        $directTransfers = $driver->getRecentDeposits('TRX');
        if (! empty($directTransfers)) {
            return $directTransfers;
        }

        $config = $gatewayModel->config ?? [];
        $depositAddress = $config['deposit_address'] ?? null;

        if (empty($depositAddress)) {
            return [];
        }

        // 2. If TronScan explorer gateway, poll recent transactions for address
        if ($gatewayModel->driver === 'tron_scan') {
            $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://apilist.tronscanapi.com'), '/');
            $apiKey = $config['api_key'] ?? null;

            $headers = [];
            if (! empty($apiKey)) {
                $headers['TRON-PRO-API-KEY'] = $apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("{$baseUrl}/api/transaction", [
                    'sort' => '-timestamp',
                    'count' => 'true',
                    'limit' => 20,
                    'start' => 0,
                    'address' => $depositAddress,
                ]);

            if ($response->successful()) {
                $data = $response->json('data') ?? [];
                $transfers = [];

                foreach ($data as $tx) {
                    $contractData = $tx['contractData'] ?? [];
                    $to = $contractData['to_address'] ?? '';
                    $amountSun = $contractData['amount'] ?? 0;
                    $amountTrx = bcdiv((string) $amountSun, '1000000', 4);

                    if (strcasecmp($to, $depositAddress) === 0 && strtoupper($tx['contractRet'] ?? '') === 'SUCCESS') {
                        $transfers[] = [
                            'tx_hash' => $tx['hash'] ?? '',
                            'amount' => $amountTrx,
                            'to_address' => $to,
                            'timestamp' => $tx['timestamp'] ?? null,
                        ];
                    }
                }

                return $transfers;
            }
        }

        return [];
    }
}

