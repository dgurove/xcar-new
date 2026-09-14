<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:digest')->dailyAt('09:00');
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('mail:reconcile')->dailyAt('04:10');
Schedule::command('queue:prune-batches')->daily();
Schedule::command('storage:gc')->dailyAt('04:30');
Schedule::command('purchases:recheck')->dailyAt('05:10');
