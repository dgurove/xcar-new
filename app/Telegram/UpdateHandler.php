<?php

namespace App\Telegram;

use App\Billing\Actions\ConfirmPayment;
use App\Billing\Actions\RecordPayment;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Billing\Robot;
use App\Notifications\CounterOfferedNotice;
use App\Notifications\TelegramChannel;
use App\Offers\Actions\AnswerCounter;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Plural;
use App\Support\Surface;
use App\Telegram\Actions\DecideLogin;
use App\Telegram\Actions\LinkChat;
use App\Telegram\Actions\LogoutTelegram;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\Messages\AgentFeeDue;
use App\Telegram\Messages\InvoiceOverdue;
use App\Telegram\Messages\PaymentClaimed;
use App\Telegram\Offers\Handler;
use App\Telegram\Offers\OffersBot;
use App\Telegram\Offers\Subscriber;
use App\Users\Access\AccessCode;
use App\Users\Access\DecideCode;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Одно обновление Telegram: нажатие кнопки под сообщением, личное
 * сообщение боту (`/start` — привязка и вход) или блокировка бота. Исключение наружу не выходит — одно битое обновление не
 * должно останавливать опрос.
 */
final class UpdateHandler
{
    public function __construct(private Bot $bot, private Journal $journal) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        // В переписку — до ответа бота, чтобы в диалоге вопрос стоял раньше ответа.
        $this->journal->received($update);
        try {
            if (is_array($update['callback_query'] ?? null)) {
                $this->press($update['callback_query']);
            } elseif (is_array($update['message'] ?? null)) {
                $this->message($update['message']);
            } elseif (is_array($update['my_chat_member'] ?? null)) {
                $this->member($update['my_chat_member']);
            }
        } catch (Throwable $e) {
            Log::error('Telegram: обновление не разобрано', ['update' => $update['update_id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function press(array $query): void
    {
        $press = Press::parse($query);
        if (! $press) {
            $this->bot->answer((string) ($query['id'] ?? ''), 'Кнопка устарела.');

            return;
        }
        // Вход подтверждает только тот, к чьему аккаунту привязан чат.
        if ($press->topic === 'login') {
            $this->login($press);

            return;
        }
        // Код от бота: «Продолжить» / «Войти» или «Это не я» — тоже только хозяин аккаунта.
        if ($press->topic === 'code') {
            $this->code($press);

            return;
        }
        // Контрпредложение — менеджеру, отвечает он сам.
        if ($press->topic === 'counter') {
            $this->counter($press);

            return;
        }
        // Кнопки лежат в чатах владельца и админов, но переслать сообщение и нажать может кто угодно.
        if (! in_array($press->fromId, $this->bot->ownerChats(), true)) {
            $this->bot->answer($press->queryId, 'Эта кнопка не для вас.');

            return;
        }
        match ($press->topic) {
            'invoice' => $this->invoice($press),
            'fee' => $this->fee($press),
            'claim' => $this->claim($press),
            default => $this->bot->answer($press->queryId, 'Кнопка устарела.'),
        };
    }

    /**
     * Ответ на контрпредложение из бота (26.10.2026): «Отказаться» — сразу, «Согласен» — переспрашивает той же строкой
     * кнопок («Да, согласен» / «Назад»): согласие сразу открывает сделку. Нажать может только сам менеджер.
     */
    private function counter(Press $press): void
    {
        $bid = Bid::with('offer')->find($press->id);
        $user = User::where('telegram_chat_id', $press->fromId)->first();
        if (! $bid || ! $user || $bid->user_id !== $user->id) {
            $this->bot->answer($press->queryId, 'Эта кнопка не для вас.');

            return;
        }
        // Строки — до ответа: согласие переписывает цену подтверждения, и «N вместо N» стало бы бессмысленным.
        $lines = implode("\n", array_map(fn ($l) => e((string) $l), array_filter(CounterOfferedNotice::telegramLines($bid), fn ($l) => $l !== null && $l !== '')));
        $text = fn (string $status) => '<b>'.e(CounterOfferedNotice::telegramTitle($bid)).'</b>'."\n".$lines
            .($status !== '' ? "\n\n".$status : '');
        $open = fn (string $label, string $path) => [[TelegramChannel::button($label, Surface::Site->url($path))]];
        $page = '/offers/'.$bid->offer->number;
        if (! $bid->hasPendingCounter()) {
            $this->bot->answer($press->queryId, 'Контрпредложение уже не действует');
            $this->bot->edit($press->chatId, $press->messageId, $text('Уже не действует'), $open('Открыть', $page));

            return;
        }
        if ($press->action === 'yes' || $press->action === 'back') {
            $this->bot->answer($press->queryId, $press->action === 'yes' ? 'Подтвердите' : '');
            $this->bot->edit($press->chatId, $press->messageId, $press->action === 'yes' ? $text('Согласиться? ТС закрепится за вами по этой цене') : $text(''),
                [$press->action === 'yes'
                    ? [['text' => 'Да, согласен', 'callback_data' => "counter:{$bid->id}:sure"], ['text' => 'Назад', 'callback_data' => "counter:{$bid->id}:back"]]
                    : [['text' => 'Согласен', 'callback_data' => "counter:{$bid->id}:yes"], ['text' => 'Отказаться', 'callback_data' => "counter:{$bid->id}:no"]],
                    ...$open('Открыть', $page)]);

            return;
        }
        $agree = $press->action === 'sure';
        try {
            app(AnswerCounter::class)($bid, $user, $agree);
        } catch (ValidationException $e) {
            $this->bot->answer($press->queryId, (string) collect($e->errors())->flatten()->first());

            return;
        }
        $deal = $agree ? $bid->offer->deal()->first() : null;
        $this->bot->answer($press->queryId, $agree ? 'Сделка открыта' : 'Отказались');
        $this->bot->edit($press->chatId, $press->messageId,
            $text($agree ? 'Вы согласились '.now()->translatedFormat('j M, H:i').', сделка открыта' : 'Вы отказались, ваша цена в силе'),
            $agree && $deal ? $open('Открыть сделку', $deal->href()) : $open('Открыть', $page));
    }

    /** «Оплачен» под просроченным счётом — оплата на весь остаток от имени владельца. */
    private function invoice(Press $press): void
    {
        $invoice = Invoice::with(['party', 'vehicle'])->find($press->id);
        if (! $invoice) {
            $this->bot->answer($press->queryId, 'Счёт удалён.');

            return;
        }
        $message = new InvoiceOverdue($invoice);
        if ($invoice->state !== InvoiceState::Issued || $invoice->remaining() <= 0) {
            $this->bot->answer($press->queryId, 'Уже '.mb_strtolower($invoice->state->label()).'.');
            $this->bot->edit($press->chatId, $press->messageId, $message->text($invoice->state->label()), $message->afterDecision());

            return;
        }
        $owner = Robot::user();
        app(RecordPayment::class)($invoice, $owner, $invoice->remaining(), null, PaymentSource::Bank, null, 'из Telegram');
        $this->bot->answer($press->queryId, 'Оплачен.');
        $this->bot->edit($press->chatId, $press->messageId, $message->text('Оплачен '.now()->translatedFormat('j M, H:i')), $message->afterDecision());
    }

    /** «Выплачено» под вознаграждением — перечисление на весь остаток от имени владельца. */
    private function fee(Press $press): void
    {
        $fee = Invoice::with(['party', 'deal.offer'])->find($press->id);
        if (! $fee) {
            $this->bot->answer($press->queryId, 'Обязательство удалено.');

            return;
        }
        $message = new AgentFeeDue($fee);
        if ($fee->state !== InvoiceState::Issued || $fee->remaining() <= 0) {
            $this->bot->answer($press->queryId, 'Уже '.mb_strtolower($fee->state->label()).'.');
            $this->bot->edit($press->chatId, $press->messageId, $message->text($fee->state->label()), $message->afterDecision());

            return;
        }
        $owner = Robot::user();
        app(RecordPayment::class)($fee, $owner, $fee->remaining(), null, PaymentSource::Bank, null, 'из Telegram');
        $this->bot->answer($press->queryId, 'Выплачено.');
        $this->bot->edit($press->chatId, $press->messageId, $message->text('Выплачено '.now()->translatedFormat('j M, H:i')), $message->afterDecision());
    }

    /** «Поступило» под сообщением менеджера — заявленная оплата становится оплатой. */
    private function claim(Press $press): void
    {
        $payment = Payment::with(['invoice.party', 'invoice.deal.offer', 'invoice.deal.buyer'])->find($press->id);
        if (! $payment) {
            $this->bot->answer($press->queryId, 'Заявка удалена.');

            return;
        }
        $message = new PaymentClaimed($payment);
        if ($payment->state !== PaymentState::Claimed) {
            $this->bot->answer($press->queryId, 'Уже '.mb_strtolower($payment->state->label()).'.');
            $this->bot->edit($press->chatId, $press->messageId, $message->text($payment->state->label()), $message->afterDecision());

            return;
        }
        $owner = Robot::user();
        app(ConfirmPayment::class)($payment, $owner);
        $this->bot->answer($press->queryId, 'Поступило.');
        $this->bot->edit($press->chatId, $press->messageId, $message->text('Поступило '.now()->translatedFormat('j M, H:i')), $message->afterDecision());
    }

    /** «Войти» или «Это не я» под «Вход на xcar.ru». */
    private function login(Press $press): void
    {
        $token = StartLink::tokenOf($press->id);
        $attempt = $token ? StartLink::attempt($token) : null;
        $user = User::where('telegram_chat_id', $press->fromId)->first();
        if (! $attempt || ! $user || ($attempt['user'] ?? $user->id) !== $user->id) {
            $this->bot->answer($press->queryId, 'Ссылка устарела');
            $this->bot->edit($press->chatId, $press->messageId, '<b>Вход на xcar.ru</b>'."\n".'Ссылка устарела');

            return;
        }
        if ($attempt['state'] !== 'wait') {
            $this->bot->answer($press->queryId, $attempt['state'] === 'ok' ? 'Уже вошли' : 'Уже отклонено');

            return;
        }
        $allow = $press->action === 'ok';
        app(DecideLogin::class)($token, $user, $allow);
        $this->bot->answer($press->queryId, $allow ? 'Входим' : 'Вход отклонён');
        $this->bot->edit($press->chatId, $press->messageId, '<b>Вход на xcar.ru</b>'."\n".e($attempt['device'])."\n\n".($allow ? 'Вошли ' : 'Отклонено ').now()->translatedFormat('j M, H:i'));
    }

    /**
     * Привязка из окна замка: чат — к аккаунту, а запись привязки одобрена — браузер, который её просил, сразу заводится
     * устройством без кода (владение этим Telegram только что доказано нажатием «Запустить»).
     */
    private function linkFromLock(int $chatId, int $id, ?string $username): void
    {
        $access = AccessCode::with('user')->find($id);
        if (! $access || $access->purpose !== 'link' || ! $access->isLive() || $access->approved_at || ! $access->user) {
            $this->reply($chatId, 'Ссылка устарела, нажмите «Подключить Telegram» ещё раз', null);

            return;
        }
        $user = $access->user;
        app(LinkChat::class)($user, $chatId, $username);
        app(DecideCode::class)($access, true);
        [$line, $button] = $this->welcome($user);
        $this->reply($chatId, '<b>'.e($user->firstName()).', готово</b>'."\n".$line, [[$button]]);
    }

    /** Кнопка под кодом (Access\SendCode): браузер, который ждёт, закончит сам. */
    private function code(Press $press): void
    {
        $access = AccessCode::with('user')->find($press->id);
        if (! $access || (int) $access->user?->telegram_chat_id !== $press->fromId) {
            $this->bot->answer($press->queryId, 'Код устарел');

            return;
        }
        if ($access->approved_at || ! $access->isLive()) {
            $this->bot->answer($press->queryId, $access->approved_at ? 'Уже подтверждено' : 'Код устарел');
            $access->approved_at || $this->bot->edit($press->chatId, $press->messageId, 'Код устарел'."\n".e($access->label));

            return;
        }
        $allow = $press->action === 'ok';
        app(DecideCode::class)($access, $allow);
        $this->bot->answer($press->queryId, $allow ? 'Готово, возвращайтесь в XCar' : 'Отклонено');
        $word = $allow ? ($access->purpose === 'login' ? 'Вошли ' : 'Подтверждено ') : 'Отклонено ';
        $this->bot->edit($press->chatId, $press->messageId, $word.now()->format('H:i')."\n".e($access->label));
    }

    /** Человек заблокировал бота — уведомлениям туда больше не пробиться. */
    private function member(array $update): void
    {
        if (data_get($update, 'chat.type') === 'private' && in_array(data_get($update, 'new_chat_member.status'), ['kicked', 'left'], true)) {
            app(UnlinkChat::class)->byChat((int) data_get($update, 'chat.id'));
        }
    }

    /** Личное сообщение: `/start` со ссылкой из приложения — привязка или вход; голый `/start` — кто на связи; остальное — молча. */
    private function message(array $message): void
    {
        $chatId = (int) data_get($message, 'chat.id', 0);
        if ($chatId === 0 || data_get($message, 'chat.type', 'private') !== 'private') {
            return;
        }
        if (preg_match('~^/logout(?:@\w+)?$~', trim((string) ($message['text'] ?? ''))) === 1) {
            $out = app(LogoutTelegram::class)($chatId);
            $this->reply($chatId, match (true) {
                $out === LogoutTelegram::LOCKED => 'Выйти нельзя, пока вход в XCar только через Telegram',
                $out instanceof User => 'Вы вышли из аккаунта '.e($out->name),
                default => 'Вы не вошли',
            }, $out === LogoutTelegram::LOCKED ? null : [[['text' => 'Войти через XCar', 'url' => ConnectLink::url(ConnectLink::MAIN)]]]);

            return;
        }
        // Ссылка из приложения — `L…`, `K…`, `I…`; прочее (`?start=hi` из приглашения владельца) — как голый /start.
        if (preg_match('~^/start(?:@\w+)?\s+([LKI]\S*)$~', trim((string) ($message['text'] ?? '')), $m) === 1) {
            $this->start($chatId, $m[1], data_get($message, 'from.username'));

            return;
        }
        // Нераспознанное — молча (решение владельца 30.09.2026: «Бот на связи» на каждое слово раздражал);
        // на голый /start — кто на связи: это первое, что человек нажимает в боте.
        if (preg_match('~^/start(?:@\w+)?(?:\s+\S+)?$~', trim((string) ($message['text'] ?? ''))) !== 1) {
            return;
        }
        // Владельцу — тот же ответ, что всем (21.10.2026: молчание на /start выглядело как сломанный бот).
        $owner = $this->bot->ownerChatId();
        if ($owner === null) {
            $this->reply($chatId, "Ваш chat_id: <code>{$chatId}</code>\nВпишите его в TELEGRAM_OWNER_CHAT_ID и перезапустите приложение", null);

            return;
        }
        $linked = User::where('telegram_chat_id', $chatId)->first();
        if ($linked) {
            $this->reply($chatId, 'Привязан к аккаунту '.e($linked->name).' на xcar.ru', [[$this->open()], ...$this->offersBot($linked)]);

            return;
        }
        // Уже подключён к боту предложений (id личного чата у ботов один) — узнаём без второго входа (21.10.2026).
        // Только у кого основной ещё не привязан: иначе уведомления молча переехали бы с его прежнего Telegram сюда.
        $known = Subscriber::where('chat_id', $chatId)->first()?->user;
        if ($known && $known->telegram_chat_id === null && ConnectLink::allows($known)) {
            $this->connected($known, $chatId, data_get($message, 'from.username'));

            return;
        }
        // Незнакомый: два бота и вход через XCar (21.10.2026, владелец: «скинул ссылку на бота — и менеджеры залогинились»).
        $this->reply($chatId, ConnectLink::intro(), [[['text' => 'Войти через XCar', 'url' => ConnectLink::url(ConnectLink::MAIN)]]]);
    }

    /** Ссылка привязки (`/start L…`, из приложения или «Войти через XCar») или узнали по боту предложений: привязка и первое слово о деле. */
    public function connected(User $user, int $chatId, ?string $username): void
    {
        app(LinkChat::class)($user, $chatId, $username);
        [$line, $button] = $this->welcome($user);
        $this->reply($chatId, '<b>'.e($user->firstName()).', готово</b>'."\n".$line, [[$button], ...$this->offersBot($user)]);
    }

    /**
     * Кнопка «Подключить @xcar_offers_bot» — тому, кому бот предложений служит и кто на него ещё не подписан.
     *
     * @return list<list<array{text: string, url: string}>>
     */
    private function offersBot(User $user): array
    {
        $url = app(OffersBot::class)->startUrl('hi');
        if (! $url || ! Handler::eligible($user) || Subscriber::where('user_id', $user->id)->whereNull('blocked_at')->exists()) {
            return [];
        }

        return [[['text' => 'Подключить @'.app(OffersBot::class)->username(), 'url' => $url]]];
    }

    private function start(int $chatId, string $payload, ?string $username): void
    {
        $link = StartLink::parse($payload);
        if ($link === null) {
            $this->reply($chatId, 'Ссылка не подходит, нажмите «Привязать» в профиле ещё раз', [[$this->profile()]]);

            return;
        }
        if ($link[0] === 'login') {
            $this->askLogin($chatId, $link[1]);

            return;
        }
        if ($link[0] === 'code') {
            $this->linkFromLock($chatId, $link[1], $username);

            return;
        }
        [, $userId, $expired] = $link;
        $user = User::find($userId);
        if (! $user || $expired) {
            $this->reply($chatId, 'Ссылка устарела, нажмите «Привязать» в профиле ещё раз', [[$this->profile()]]);

            return;
        }
        $this->connected($user, $chatId, $username);
    }

    /**
     * Первое слово бота после «Запустить» — о деле, а не о боте: сколько сделок в работе и где ждут ответа менеджера.
     *
     * @return array{0: string, 1: array{text: string, url: string}}
     */
    private function welcome(User $user): array
    {
        // Модератор: новые цепочки «Из писем» (с галкой почты) и вход в CRM — кнопка в CRM, не на сайт.
        if ($user->isModerator()) {
            return [$user->canCrmMail() ? 'Сюда придут заявки с почты, которые надо завести' : 'Заявки с почты придут, когда вам откроют почту в CRM',
                ['text' => 'Открыть CRM', 'url' => Surface::Crm->url('/')]];
        }
        // Управляющий парковкой (без сайта): заявки, продажи, сроки и оплаты — кнопка на парковку.
        if (! $user->usesSite()) {
            return ['Сюда придут заявки и письма, продажи, сроки и оплаты парковки', ['text' => 'Открыть парковку', 'url' => Surface::Park->url('/requests')]];
        }
        // Покупатель: машины, что откроет менеджер, и его сообщения — кнопка в ленту.
        if ($user->isBuyer()) {
            return ['Сюда придут автомобили, которые откроет '.($user->manager?->shortName() ?? 'ваш менеджер').', и сообщения из чата', ['text' => 'Открыть предложения', 'url' => Surface::Site->url('/offers')]];
        }
        if (! $user->hasDeals()) {
            return ['Сюда придут оплаты, выплаты и просроченные счета с кнопкой решения', $this->open()];
        }
        $deals = Deal::where('buyer_id', $user->id)->where('state', DealState::Active)->with('openRequirement')->get();
        if ($deals->isEmpty()) {
            return ['Напишем, когда выберут ваше подтверждение', $this->open()];
        }
        $waiting = $deals->filter(fn (Deal $d) => $d->openRequirement !== null);
        $line = 'В работе '.$deals->count().' '.Plural::of($deals->count(), ['сделка', 'сделки', 'сделок'])
            .match ($waiting->count()) {
                0 => '', 1 => ', в одной ждут ваш ответ', default => ', в '.$waiting->count().' ждут ваш ответ'
            };

        return $waiting->isEmpty()
            ? [$line, ['text' => 'Открыть сделки', 'url' => Surface::Site->url('/deals')]]
            : [$line, ['text' => 'Открыть сделку', 'url' => Surface::Site->url('/deals/'.$waiting->first()->id)]];
    }

    /** Браузер просит войти: спрашиваем в чате, привязанном к аккаунту. */
    private function askLogin(int $chatId, string $token): void
    {
        $attempt = StartLink::attempt($token);
        $user = User::where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            $this->reply($chatId, 'Этот Telegram не привязан к xcar.ru. Войдите по паролю и привяжите его в профиле', [[['text' => 'Войти по паролю', 'url' => Surface::Site->url('/login')]]]);

            return;
        }
        if (! $attempt || $attempt['state'] !== 'wait') {
            $this->reply($chatId, 'Ссылка устарела, нажмите «Войти через Telegram» ещё раз', null);

            return;
        }
        $n = StartLink::number($token);
        $this->reply($chatId, '<b>Вход на xcar.ru</b>'."\n".e($attempt['device'])."\n".e($user->name), [[
            ['text' => 'Войти', 'callback_data' => "login:{$n}:ok"],
            ['text' => 'Это не я', 'callback_data' => "login:{$n}:no"],
        ]]);
    }

    private function open(): array
    {
        return ['text' => 'Открыть xcar', 'url' => Surface::Site->url('/')];
    }

    private function profile(): array
    {
        return ['text' => 'Профиль на xcar.ru', 'url' => Surface::Site->url('/account')];
    }

    private function reply(int $chatId, string $text, ?array $keyboard): void
    {
        try {
            $this->bot->send($chatId, $text, $keyboard);
        } catch (Throwable $e) {
            Log::warning('Telegram: не ответил на сообщение', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
