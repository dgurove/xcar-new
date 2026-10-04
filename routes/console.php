<?php

use App\Users\Actions\CloseStaleImpersonations;
use Illuminate\Support\Facades\Artisan;
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
// Архив Мигторга кусками: до 150 карточек раз в 5 минут, пока не пройдены 60 дней торгов назад (потом молчит).
Schedule::command('migtorg:archive')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
// Входы админа за человека, из которых не вышли кнопкой, — закрыть сроком сессии.
Schedule::call(fn () => app(CloseStaleImpersonations::class)())->hourly()->name('impersonations:close');

// Разовая правка 04.10.2026: ТС, отданная в гараж «взяли под себя», — на деле вывоз «Мы → К нам» (предложение снова
// черновиком, продаётся обычным путём). Без --apply только печатает. Удалить после прогона на проде.
Artisan::command('garage:to-pickup {offer} {--apply}', function (string $offer) {
    $o = \App\Offers\OfferNumber::find($offer) ?? abort(1, 'Нет предложения');
    $car = \App\Garage\Car::where('offer_id', $o->id)->first();
    $this->line("№ {$o->number} {$o->titleWithYear()}: гараж ".($car ? $car->state->value.', менеджер '.($car->manager_id ?? '—').', сделка '.($car->deal_id ?? '—').', расходов '.$car->costs()->count() : 'нет'));
    $this->line('Маршрут вывоза у вендора: '.($o->vendor?->workflow(\App\Workflow\Track::Service)?->is_active ? 'есть' : 'нет'));
    if (! $car || $car->manager_id || $car->deal_id || $car->costs()->exists()) {
        return $this->error('Не подходит: нужна строка гаража без менеджера, сделки и расходов');
    }
    if (! $this->option('apply')) {
        return $this->info('Проверка прошла, запустите с --apply');
    }
    $by = \App\Users\User::withRole(\App\Users\Role::Admin)->orderBy('id')->firstOrFail();
    app(\App\Garage\Actions\ReturnFromGarage::class)($car, $by);
    $o = $o->fresh()->load('vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests');
    app(\App\Offers\Actions\AssignPickup::class)($o, null, \App\Offers\Destination::Ours, $by);
    $this->info('Готово: '.$o->fresh()->state->value.', вывоз '.$o->fresh()->evacuation_to);
});
