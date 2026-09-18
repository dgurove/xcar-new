<?php

namespace App\Telegram;

use App\Billing\Actions\RecordPayment;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Telegram\Messages\InvoiceOverdue;
use App\Telegram\Messages\Registration;
use App\Users\Actions\DecideAccess;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Одно обновление Telegram: нажатие кнопки под сообщением или личное
 * сообщение боту. Исключение наружу не выходит — одно битое обновление не
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
        // Кнопки лежат в чате владельца, но переслать сообщение и нажать может кто угодно.
        if ($press->fromId !== $this->bot->ownerChatId()) {
            $this->bot->answer($press->queryId, 'Эта кнопка не для вас.');

            return;
        }
        match ($press->topic) {
            'access' => $this->access($press),
            'invoice' => $this->invoice($press),
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
        $owner = User::where('role', Role::Admin)->orderBy('id')->firstOrFail();
        app(RecordPayment::class)($invoice, $owner, $invoice->remaining(), null, PaymentSource::Bank, null, 'из Telegram');
        $this->bot->answer($press->queryId, 'Оплачен.');
        $this->bot->edit($press->chatId, $press->messageId, $message->text('Оплачен '.now()->translatedFormat('j M, H:i')), $message->afterDecision());
    }

    /** Личное сообщение: пока владелец не задан, бот отвечает chat_id — иначе узнать его нечем. */
    private function message(array $message): void
    {
        $chatId = (int) data_get($message, 'chat.id', 0);
        if ($chatId === 0) {
            return;
        }
        $owner = $this->bot->ownerChatId();
        $text = match (true) {
            $owner === null => "Ваш chat_id: <code>{$chatId}</code>\nВпишите его в TELEGRAM_OWNER_CHAT_ID и перезапустите приложение.",
            $chatId === $owner => 'Бот на связи: сюда приходят регистрации.',
            default => 'Служебный бот xcar.ru.',
        };
        try {
            $this->bot->send($chatId, $text);
        } catch (Throwable $e) {
            Log::warning('Telegram: не ответил на сообщение', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
