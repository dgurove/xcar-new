<?php

use App\Mail\Message;
use App\Notifications\Delivery;
use App\Offers\DealState;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Offers\ReadOffer;
use App\Offers\ReplyReview;
use App\Users\Access\AccessCode;
use App\Users\Actions\CloseStaleImpersonations;
use App\Users\Role;
use App\Users\User;
use App\Users\Visit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('offers:tick')->everyMinute()->withoutOverlapping();
Schedule::command('park:tick')->everyFiveMinutes()->withoutOverlapping();
// Номера «К запросу» письмом в 9:00 и 18:00 (`claims:digest`): каждые 5 минут — пропущенный слот уходит следующим проходом.
Schedule::command('claims:digest')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('logistics:tick')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:tick')->dailyAt('09:05');
// Прозрачности водяных знаков по снятым и почищенным вручную кадрам — ночью, процессор свободен.
Schedule::command('media:unmark-tune')->dailyAt('04:20')->withoutOverlapping();
// Файлы из писем выданных ТС — только когда месяц выдачи закрыт и оплачен (`LettersPurge::due`, 06.11.2026).
Schedule::command('park:purge-letters --apply')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('billing:close-month')->monthlyOn(1, '06:00');
// Оплаты по ссылкам — подстраховка уведомлений ЮKassa; выписка Сбера — днём сегодняшняя, утром вчерашняя целиком и сводка.
Schedule::command('acquiring:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bank:sync')->everyTenMinutes()->between('7:00', '23:00')->withoutOverlapping()->runInBackground();
Schedule::command('bank:sync --days=1 --digest')->dailyAt('06:10')->withoutOverlapping();
Schedule::command('mail:sync')->everyMinute()->withoutOverlapping()->runInBackground();
// Без withoutOverlapping: очередь держит замок Postgres в самой команде — новый опрос ждёт прежний и подхватывает без паузы.
Schedule::command('telegram:poll')->everyMinute()->runInBackground();
// WhatsApp (`WhatsApp\Sender`): группа, личные, оценка — паузы между сообщениями внутри минуты самой командой; коды
// привязки — длинным опросом эстафетой, как Telegram (привязка работает и при выключенной рассылке).
Schedule::command('whatsapp:poll')->everyMinute()->runInBackground()->when(fn () => filled(config('xcar.whatsapp.via')));
Schedule::command('whatsapp:tick')->everyMinute()->withoutOverlapping()->runInBackground()
    ->when(fn () => filled(config('xcar.whatsapp.via')) && filled(config('xcar.whatsapp.since')));
Schedule::command('mail:reconcile')->dailyAt('04:10');
Schedule::command('mail:archive-stale')->dailyAt('04:20');
Schedule::command('queue:prune-batches')->daily();
Schedule::command('storage:gc')->dailyAt('04:30');
Schedule::command('offers:prune-drafts')->dailyAt('04:40');
Schedule::call(fn () => ReadOffer::prune())->dailyAt('04:45')->name('offer-reads:prune');
Schedule::call(fn () => Delivery::prune())->dailyAt('04:47')->name('notice-deliveries:prune');
Schedule::call(fn () => Visit::prune())->dailyAt('04:48')->name('user-visits:prune');
// Номера дел лотов Мигторга, фото совпавшим предложениям и черновики новых лотов: открытый список без входа, 5–8
// запросов раз в 10 минут (26.10.2026: черновик Альфы и Т — как можно раньше). Ночью — раз в час (30.10.2026: КАСКО
// выходит в будни 10–19, торги идут от 40 ч — ночной опрос раз в 10 минут ничего не находил). Обход архива
// (`migtorg:archive`) пройден 05.10.2026 и из расписания снят — команда для ручного запуска. Одной записью: у двух записей
// одной команды разные замки `withoutOverlapping`, и дневной прогон мог идти вместе с ночным.
Schedule::command('migtorg:sync')->everyTenMinutes()->when(fn () => (now()->hour >= 8 && now()->hour < 21) || now()->minute < 10)
    ->withoutOverlapping()->runInBackground();
// Коды от бота (замок менеджеров) живут минуты — старше двух дней не нужны.
Schedule::call(fn () => AccessCode::where('created_at', '<', now()->subDays(2))->delete())->dailyAt('04:50')->name('access-codes:prune');
// Входы админа за человека, из которых не вышли кнопкой, — закрыть сроком сессии.
Schedule::call(fn () => app(CloseStaleImpersonations::class)())->hourly()->name('impersonations:close');
// Медленные ответы за день владельцу (`ServerTiming` пишет `slow-*.log`, 10.11.2026) — вечером, когда работа кончилась.
Schedule::command('perf:digest')->dailyAt('20:30');
// Сессии гостей (боты, сканеры, проверка живости) — сутки, а не срок входа в 30 дней: 160 тыс. мёртвых строк на 10.11.2026.
Schedule::call(fn () => DB::table('sessions')->whereNull('user_id')->where('last_activity', '<', now()->subDay()->getTimestamp())->delete())
    ->dailyAt('04:55')->name('guest-sessions:prune');

/*
 * Разово (10.11.2026): разборы писем, по которым сотрудник уже сдвинул шаг, и разборы ушедших сделок — разобраны. Дальше это
 * делает `Offers\Listeners\CloseReviews` на каждый шаг и ответ поставщику.
 */
Artisan::command('deals:close-stale-reviews {--dry}', function () {
    $stale = ReplyReview::pending()->with(['deal', 'offer', 'message'])->get()->filter(function ($r) {
        if ($r->deal?->state !== DealState::Active) {
            return true;
        }

        $staff = User::withRole(Role::Admin, Role::Moderator)->select('id');

        // Те же правила, что у `Offers\Listeners\CloseReviews`: сотрудник сдвинул шаг или ответил в той же ветке после письма.
        return OfferEvent::where('offer_id', $r->offer_id)->where('type', OfferEventType::StageEntered)
            ->where('created_at', '>', $r->created_at)->where('payload->track', 'sale')->whereIn('user_id', $staff)->exists()
            || ($r->message && Message::where('thread_id', $r->message->thread_id)->where('direction', 'out')
                ->where('date_at', '>', $r->message->date_at)->whereIn('created_by', $staff)->exists());
    });
    foreach ($stale as $r) {
        $this->line($r->id.' сделка '.$r->deal_id.' '.$r->offer?->number.' '.$r->created_at->format('d.m H:i').' '.($r->deal?->state->value ?? '—'));
    }
    $this->info(($this->option('dry') ? 'Закрыл бы: ' : 'Закрыто: ').$stale->count());
    if (! $this->option('dry') && $stale->isNotEmpty()) {
        ReplyReview::whereIn('id', $stale->pluck('id'))->update(['state' => ReplyReview::DONE, 'done_at' => now()]);
    }
})->purpose('Закрыть разборы писем, по которым шаг уже сдвинули');

/*
 * Разово (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»; идущим — с уведомлением): сделки, где менеджер
 * уже согласился, а покупателя нет или он без данных, получают дело «Укажите покупателя» (`AskBuyer`), как новые после «Беру в
 * работу». На шаге «Нет реквизитов» и оплаты — нет: там покупателя просит сам шаг.
 */
Artisan::command('deals:ask-buyers {--dry}', function () {
    $deals = \App\Offers\Deal::with(['buyer', 'offer', 'contract.buyer.party'])->where('state', DealState::Active)->whereNotNull('agreed_at')
        ->whereNull('buyer_asked_at')->get()->filter(function ($d) {
            $stage = $d->offer?->stage();

            return $d->buyer && $stage && $stage->asks !== \App\Workflow\Asks::Buyer && ! $stage->isPayStep() && ! $stage->isRequisitesStep()
                && $d->buyerGap() !== null;
        });
    foreach ($deals as $d) {
        $this->line($d->id.' '.$d->offer->number.' '.$d->offer->titleWithYear().', '.$d->buyer->shortName().', '.$d->offer->stage()->name.': '.$d->buyerGap());
    }
    $this->info(($this->option('dry') ? 'Попросил бы: ' : 'Попросил: ').$deals->count());
    if (! $this->option('dry')) {
        $deals->each(fn ($d) => app(\App\Offers\Actions\AskBuyer::class)->ask($d));
    }
})->purpose('Дело «Укажите покупателя» сделкам, где менеджер уже согласился');
