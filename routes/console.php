<?php

use App\Notifications\Delivery;
use App\Offers\ReadOffer;
use App\Users\Access\AccessCode;
use App\Users\Actions\CloseStaleImpersonations;
use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('park:tick')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('logistics:tick')->everyFifteenMinutes()->withoutOverlapping();
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
Schedule::call(fn () => ReadOffer::prune())->dailyAt('04:45')->name('offer-reads:prune');
Schedule::call(fn () => Delivery::prune())->dailyAt('04:47')->name('notice-deliveries:prune');
// Номера дел лотов Мигторга, фото совпавшим предложениям и черновики новых лотов: открытый список без входа, 5–8
// запросов раз в 10 минут (26.10.2026: черновик Альфы и Т — как можно раньше). Ночью — раз в час (30.10.2026: КАСКО
// выходит в будни 10–19, торги идут от 40 ч — ночной опрос раз в 10 минут ничего не находил). Обход архива
// (`migtorg:archive`) пройден 05.10.2026 и из расписания снят — команда для ручного запуска.
Schedule::command('migtorg:sync')->everyTenMinutes()->between('8:00', '21:00')->withoutOverlapping()->runInBackground();
Schedule::command('migtorg:sync')->hourly()->unlessBetween('8:00', '21:00')->withoutOverlapping()->runInBackground();
// Коды от бота (замок менеджеров) живут минуты — старше двух дней не нужны.
Schedule::call(fn () => AccessCode::where('created_at', '<', now()->subDays(2))->delete())->dailyAt('04:50')->name('access-codes:prune');
// Входы админа за человека, из которых не вышли кнопкой, — закрыть сроком сессии.
Schedule::call(fn () => app(CloseStaleImpersonations::class)())->hourly()->name('impersonations:close');
