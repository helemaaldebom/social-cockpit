<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('social:fetch-rss')->dailyAt('06:00');
Schedule::command('social:process-scheduler')->hourly();
// Uurlijks handmatig in Publer ingeplande posts importeren (op :17 zodat het
// niet botst met de scheduler op :00). Geeft ze een Cockpit-item + reminder.
Schedule::command('social:sync-publer')->cron('17 * * * *')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:00');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
