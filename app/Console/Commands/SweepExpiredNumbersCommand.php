<?php

namespace App\Console\Commands;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SweepExpiredNumbersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'numbers:sweep-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired reserved phone numbers and update status of overdue lines';

    /**
     * Execute the console command.
     */
    public function handle(PhoneNumberAllocationServiceInterface $allocationService): int
    {
        $this->info('Checking and processing expired phone numbers in the platform...');

        $processedCount = $allocationService->processExpiredNumbers();

        Log::info("Number expiration sweep executed: {$processedCount} numbers processed.");

        $this->info("Successfully processed and released {$processedCount} expired phone numbers.");

        return Command::SUCCESS;
    }
}