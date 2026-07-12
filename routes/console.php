<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Notificaciones automáticas (módulo 17).
Schedule::command('notifications:today-citations')->dailyAt('07:00');
Schedule::command('notifications:missing-attendance')->dailyAt('08:00');
Schedule::command('notifications:missing-homework-grades')->dailyAt('08:30');
Schedule::command('notifications:uncontacted-behavior')->daily();
Schedule::command('notifications:closing-periods')->daily();
Schedule::command('notifications:upcoming-class-push')->everyMinute();
