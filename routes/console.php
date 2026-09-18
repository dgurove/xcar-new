<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('park:tick')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('park:digest')->weekdays()->dailyAt('09:00');
Schedule::command('billing:tick')->dailyAt('09:05');
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('mail:reconcile')->dailyAt('04:10');
Schedule::command('queue:prune-batches')->daily();
Schedule::command('storage:gc')->dailyAt('04:30');
