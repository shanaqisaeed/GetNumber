<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Active Queue Worker & Job Processing
|--------------------------------------------------------------------------
| Continuously process queued jobs across all channels.
| Automatically runs in the background and drains jobs every minute.
*/
Schedule::command('queue:work --stop-when-empty --max-time=55 --memory=128 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:restart')
    ->hourly()
    ->runInBackground();

Schedule::command('queue:prune-batches --hours=48')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Financial & Blockchain Schedulers
|--------------------------------------------------------------------------
*/
Schedule::command('payments:verify-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('blockchain:watch-deposits')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('financial:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('payments:nobitex:sync-wallet')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Telephony & Message Lifecycle Schedulers
|--------------------------------------------------------------------------
*/
Schedule::command('numbers:sweep-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('messages:archive --days=90')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
