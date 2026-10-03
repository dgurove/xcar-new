<?php

use App\Users\Actions\CloseStaleImpersonations;
use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('park:tick')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('park:digest')->weekdays()->dailyAt('09:00');
Schedule::command('billing:tick')->dailyAt('09:05');
// Прозрачности водяных знаков по снятым и почищенным вручную кадрам — ночью, процессор свободен.
Schedule::command('media:unmark-tune')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('billing:close-month')->monthlyOn(1, '06:00');
// Оплаты по ссылкам — подстраховка уведомлений ЮKassa; выписка Сбера — днём сегодняшняя, утром вчерашняя целиком и сводка.
Schedule::command('acquiring:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bank:sync')->everyTenMinutes()->between('7:00', '23:00')->withoutOverlapping()->runInBackground();
Schedule::command('bank:sync --days=1 --digest')->dailyAt('06:10')->withoutOverlapping();
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping()->runInBackground();
// Без withoutOverlapping: очередь держит замок Postgres в самой команде — новый опрос ждёт прежний и подхватывает без паузы.
Schedule::command('telegram:poll')->everyMinute()->runInBackground();
Schedule::command('mail:reconcile')->dailyAt('04:10');
Schedule::command('mail:archive-stale')->dailyAt('04:20');
Schedule::command('queue:prune-batches')->daily();
Schedule::command('storage:gc')->dailyAt('04:30');
Schedule::command('offers:prune-drafts')->dailyAt('04:40');
// Номера дел лотов Мигторга и фото совпавшим предложениям: пять запросов списка раз в полчаса.
Schedule::command('migtorg:sync')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();
// Входы админа за человека, из которых не вышли кнопкой, — закрыть сроком сессии.
Schedule::call(fn () => app(CloseStaleImpersonations::class)())->hourly()->name('impersonations:close');
