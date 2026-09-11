<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:digest')->dailyAt('09:00');
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('mail:reconcile')->dailyAt('04:10');
Schedule::command('queue:prune-batches')->daily();
