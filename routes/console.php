<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('social:fetch-rss')->dailyAt('06:00');
Schedule::command('social:process-scheduler')->hourly();
Schedule::command('backup:run')->dailyAt('03:00');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
