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
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Plural;
use App\Support\Surface;
use App\Telegram\Actions\DecideLogin;
use App\Telegram\Actions\LinkChat;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\Messages\AgentFeeDue;
use App\Telegram\Messages\InvoiceOverdue;
use App\Telegram\Messages\PaymentClaimed;
use App\Telegram\Messages\Registration;
use App\Users\Actions\DecideAccess;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Одно обновление Telegram: нажатие кнопки под сообщением, личное
 * сообщение боту (`/start` — привязка и вход) или блокировка бота. Исключение наружу не выходит — одно битое обновление не
 * должно останавливать опрос.
 */
final class UpdateHandler
{
    public function __construct(private Bot $bot, private DecideAccess $decide) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
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
        // Кнопки лежат в чатах владельца и админов, но переслать сообщение и нажать может кто угодно.
        if (! in_array($press->fromId, $this->bot->ownerChats(), true)) {
            $this->bot->answer($press->queryId, 'Эта кнопка не для вас.');

            return;
        }
        match ($press->topic) {
            'access' => $this->access($press),
            'invoice' => $this->invoice($press),
            'fee' => $this->fee($press),
            'claim' => $this->claim($press),
            default => $this->bot->answer($press->queryId, 'Кнопка устарела.'),
        };
    }

    private function access(Press $press): void
    {
        $user = User::find($press->id);
        if (! $user) {
            $this->bot->answer($press->queryId, 'Пользователь удалён.');

            return;
        }
        $message = new Registration($user);
        // Клиент мог показать старую клавиатуру: по уже решённому не перерешаем, только переписываем след.
        if (! $user->isPending()) {
            $this->bot->answer($press->queryId, $user->isRejected() ? 'Уже отклонён.' : "Уже решено: {$user->role->label()}.");
            $this->bot->edit($press->chatId, $press->messageId, $message->text($message->decided()), $message->afterDecision());

            return;
        }
        if ($press->action === 'reject') {
            $this->decide->reject($user);
            $this->bot->answer($press->queryId, 'Отклонён.');
        } elseif ($role = Role::tryFrom($press->action)) {
            $this->decide->approve($user, $role);
            $this->bot->answer($press->queryId, "Роль: {$role->label()}, доступ открыт");
        } else {
            $this->bot->answer($press->queryId, 'Такой роли нет.');

            return;
        }
        $this->bot->edit($press->chatId, $press->messageId, $message->text($message->decided()), $message->afterDecision());
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

    /** Человек заблокировал бота — уведомлениям туда больше не пробиться. */
    private function member(array $update): void
    {
        if (data_get($update, 'chat.type') === 'private' && in_array(data_get($update, 'new_chat_member.status'), ['kicked', 'left'], true)) {
            app(UnlinkChat::class)->byChat((int) data_get($update, 'chat.id'));
        }
    }

    /** Личное сообщение: `/start` со ссылкой из приложения — привязка или вход; без неё — кто на связи. */
    private function message(array $message): void
    {
        $chatId = (int) data_get($message, 'chat.id', 0);
        if ($chatId === 0 || data_get($message, 'chat.type', 'private') !== 'private') {
            return;
        }
        if (preg_match('~^/start(?:@\w+)?\s+(\S+)$~', trim((string) ($message['text'] ?? '')), $m) === 1) {
            $this->start($chatId, $m[1], data_get($message, 'from.username'));

            return;
        }
        $owner = $this->bot->ownerChatId();
        $linked = User::where('telegram_chat_id', $chatId)->first();
        [$text, $keyboard] = match (true) {
            $owner === null => ["Ваш chat_id: <code>{$chatId}</code>\nВпишите его в TELEGRAM_OWNER_CHAT_ID и перезапустите приложение", null],
            $linked !== null => ['Привязан к аккаунту '.e($linked->name).' на xcar.ru', [[$this->open()]]],
            $chatId === $owner => ['Бот на связи', null],
            default => ['Служебный бот xcar.ru', [[$this->profile()]]],
        };
        $this->reply($chatId, $text, $keyboard);
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
        [, $userId, $expired] = $link;
        $user = User::find($userId);
        if (! $user || $expired) {
            $this->reply($chatId, 'Ссылка устарела, нажмите «Привязать» в профиле ещё раз', [[$this->profile()]]);

            return;
        }
        app(LinkChat::class)($user, $chatId, $username);
        [$line, $button] = $this->welcome($user);
        $this->reply($chatId, '<b>'.e($user->firstName()).', готово</b>'."\n".$line, [[$button]]);
    }

    /**
     * Первое слово бота после «Запустить» — о деле, а не о боте: сколько сделок в работе и где ждут ответа менеджера.
     *
     * @return array{0: string, 1: array{text: string, url: string}}
     */
    private function welcome(User $user): array
    {
        if (! $user->isManager()) {
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
