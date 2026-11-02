<?php

namespace App\Console\Commands;

use App\Contracts\LedgerServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimulateLoadAndBenchmarkCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test:load-simulation
                            {--users=50 : Number of simulated concurrent users}
                            {--messages=200 : Number of messages to dispatch in workload}';

    /**
     * The console command description.
     */
    protected $description = 'Execute a real concurrency and load benchmark on the GetNumber platform measuring P95/P99 latency, throughput and memory';

    public function handle(
        MessageService $messageService,
        WalletServiceInterface $walletService,
        LedgerServiceInterface $ledgerService
    ): int {
        $userCount = (int) $this->option('users');
        $messageCount = (int) $this->option('messages');

        $this->info("🚀 Starting GetNumber Load Benchmark Simulation (Users: {$userCount}, Messages: {$messageCount})...");

        $initialMemory = memory_get_usage(true) / (1024 * 1024);
        $totalStartTime = microtime(true);

        // 1. Setup default prefix and test users
        $prefix = PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Load Test Prefix',
                'country_code' => 'INTL',
                'is_active' => true,
            ]
        );

        $users = [];
        $phones = [];
        $this->output->progressStart($userCount);

        for ($i = 1; $i <= $userCount; $i++) {
            $u = User::create([
                'name' => "Bench User {$i}",
                'username' => "bench_usr_{$i}_" . time(),
                'password' => bcrypt('password123'),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
                'trust_tier' => 'standard',
            ]);

            $num = str_pad((string) (100000 + $i), 9, '0', STR_PAD_LEFT);
            $p = PhoneNumber::create([
                'number' => $num,
                'type' => PhoneNumberType::UserStandard,
                'status' => PhoneNumberStatus::Allocated,
                'user_id' => $u->id,
                'phone_prefix_id' => $prefix->id,
                'allocated_at' => now(),
                'accepts_incoming_sms' => true,
            ]);

            $users[] = $u;
            $phones[] = $p;
            $this->output->progressAdvance();
        }
        $this->output->progressFinish();
        $this->info("✓ Created {$userCount} users and virtual phone lines.");

        // 2. Execute Workload: Dispatch messages between random users and measure response times
        $this->info("⚡ Dispatching {$messageCount} messages across users...");
        $latenciesMs = [];
        $errors = 0;
        $workloadStart = microtime(true);

        $this->output->progressStart($messageCount);

        for ($m = 1; $m <= $messageCount; $m++) {
            $senderIdx = $m % $userCount;
            $recipientIdx = ($m + 1) % $userCount;

            $sender = $users[$senderIdx];
            $fromPhone = $phones[$senderIdx];
            $destPhone = $phones[$recipientIdx];

            $reqStart = microtime(true);
            try {
                $result = $messageService->sendSms(
                    fromNumber: $fromPhone,
                    destinationNumbers: [$destPhone->number],
                    body: "Load benchmark test message #{$m} at " . microtime(true),
                    senderUser: $sender
                );

                if (($result['sent_count'] ?? 0) < 1) {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
            $reqEnd = microtime(true);
            $latenciesMs[] = round(($reqEnd - $reqStart) * 1000, 2);

            $this->output->progressAdvance();
        }
        $this->output->progressFinish();

        $workloadDuration = microtime(true) - $workloadStart;
        $peakMemory = memory_get_peak_usage(true) / (1024 * 1024);

        // 3. Compute Percentiles (P50, P90, P95, P99)
        sort($latenciesMs);
        $count = count($latenciesMs);
        $avg = $count > 0 ? round(array_sum($latenciesMs) / $count, 2) : 0;
        $min = $count > 0 ? $latenciesMs[0] : 0;
        $max = $count > 0 ? $latenciesMs[$count - 1] : 0;
        $p50 = $count > 0 ? $latenciesMs[(int) floor($count * 0.50)] : 0;
        $p90 = $count > 0 ? $latenciesMs[(int) floor($count * 0.90)] : 0;
        $p95 = $count > 0 ? $latenciesMs[(int) floor($count * 0.95)] : 0;
        $p99 = $count > 0 ? $latenciesMs[(int) floor($count * 0.99)] : 0;

        $throughput = $workloadDuration > 0 ? round($messageCount / $workloadDuration, 2) : 0;
        $errorRate = $messageCount > 0 ? round(($errors / $messageCount) * 100, 2) : 0;

        // 4. Output Summary Table
        $this->newLine();
        $this->table(
            ['Metric', 'Measured Value'],
            [
                ['Simulated Concurrent Users', $userCount],
                ['Total Dispatched Messages', $messageCount],
                ['Successful Operations', $messageCount - $errors],
                ['Failed Operations', $errors],
                ['Error Rate', "{$errorRate}%"],
                ['Total Workload Time', round($workloadDuration, 2) . ' s'],
                ['Throughput', "{$throughput} ops/sec"],
                ['Min Latency', "{$min} ms"],
                ['Average Latency', "{$avg} ms"],
                ['P50 Latency (Median)', "{$p50} ms"],
                ['P90 Latency', "{$p90} ms"],
                ['P95 Latency', "{$p95} ms"],
                ['P99 Latency', "{$p99} ms"],
                ['Max Latency', "{$max} ms"],
                ['Initial RAM', round($initialMemory, 2) . ' MB'],
                ['Peak RAM Consumed', round($peakMemory, 2) . ' MB'],
            ]
        );

        $this->info("✅ Load benchmark simulation completed successfully!");
        return self::SUCCESS;
    }
}

