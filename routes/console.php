<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('social:fetch-rss')->dailyAt('06:00');
Schedule::command('social:process-scheduler')->hourly();
// Uurlijks handmatig in Publer ingeplande posts importeren (op :17 zodat het
// niet botst met de scheduler op :00). Geeft ze een Cockpit-item + reminder.
Schedule::command('social:sync-publer')->cron('17 * * * *')->withoutOverlapping();
// Na het geplande tijdstip controleren of posts écht live staan.
Schedule::command('social:verify-published')->cron('35 * * * *')->withoutOverlapping();
// Wekelijkse review-herinnering (maandag 08:00). Plaatst zelf niets.
Schedule::command('social:review-digest')->weeklyOn(1, '08:00')->timezone('Europe/Amsterdam');
Schedule::command('backup:run')->dailyAt('03:00');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
