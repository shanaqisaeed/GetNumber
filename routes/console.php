<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('numbers:sweep-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('payments:verify-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

