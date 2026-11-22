<?php

namespace App\Notifications;

use App\Chats\Actions\AutoReply;
use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Jobs\RetractChatMessage;
use App\Chats\Message;
use App\Push\WebPushChannel;
use App\Support\Surface;
use App\Telegram\Text;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Сообщение в чате: пуш и Telegram — на каждое (пуши одного чата заменяют друг друга по tag, в Telegram — каждое со
 * звуком, решение владельца 01.10.2026), в ленту и на почту — только первое непрочитанное, лента держит строку на чат.
 * Адрес — экран чата в кабинете без хоста: сотруднику на CRM он станет `/work/chats/{id}`, на сайте откроется там же.
 * forStaff — адресат вторая сторона.
 */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff, private bool $first = true) {}

    public function via(User $user): array
    {
        $via = parent::via($user);
        // Сотруднику — каждое сообщение и строкой ленты (18.11.2026, начальник: «всё, что в Telegram, — и в центр уведомлений»);
        // менеджеру и покупателю — первое непрочитанное, строка на чат.
        $via = $this->first || ! $this->toManagerSide($user) ? array_values($via) : array_values(array_intersect($via, [WebPushChannel::class, TelegramChannel::class]));
        // Менеджеру (и покупателю) — в Telegram каждое сообщение чата, кто бы ни писал, даже с выключенными чатами в настройках
        // (16.11.2026, владелец: «всё, что ему в чат приходит, — в телегу: администратор, модератор, покупатель»). Сотрудникам —
        // по их настройкам, как было.
        // С 19.11.2026 у менеджера и покупателя без ролей CRM — по теме «Чаты» («тема × канал», `Categories::topics`):
        // по умолчанию включено, выключил — его выбор.
        if ($this->toManagerSide($user) && ! Categories::siteOnly($user) && $user->wantsTelegram() && ! in_array(TelegramChannel::class, $via, true) && $this->toTelegram() !== null) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    /** Адресат — не сотрудник площадки: менеджер чата (ему пишет покупатель или площадка) или покупатель. */
    private function toManagerSide(User $user): bool
    {
        // Сотрудник в своей личке (22.11.2026) — как в чате площадки: каждое сообщение строкой ленты, Telegram — по его настройкам.
        if ($this->toStaffInDirect($user)) {
            return false;
        }

        return ! $this->forStaff || ($this->message->chat->manager_id !== null && $this->message->chat->manager_id === $user->id);
    }

    /** Личка, а адресат — сотрудник (`canEditDeals`): ему чат — работа CRM (`/work/chats/{id}`), а не сайта. */
    private function toStaffInDirect(?User $user = null): bool
    {
        $chat = $this->message->chat;
        $user ??= $this->forStaff ? $chat->manager : $chat->user;

        return $chat->isDirect() && (bool) $user?->canEditDeals();
    }

    /**
     * Файлы сообщения — в Telegram вслед за текстом, ответом на него (`TelegramChannel`, 16.11.2026: «всё в телегу» — и счёт
     * страховой вложением, и фото от покупателя). @return \Illuminate\Support\Collection<int, \App\Chats\File>
     */
    public function telegramFiles(): Collection
    {
        return $this->message->loadMissing('files')->files->take(10)->values();
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function forStaff(): bool
    {
        return $this->forStaff;
    }

    public function subject(): string
    {
        return Chat::subject($this->message->chat_id);
    }

    /** Сообщения бота об этом сообщении чата — удалить у всех, когда его удалили (`Chats\Jobs\RetractChatMessage`, 17.11.2026). */
    public function telegramSubject(): ?string
    {
        return RetractChatMessage::subjectOf($this->message->id);
    }

    /** Строка ленты знает своё сообщение — убрать её вместе с ним. */
    public function toArray(User $user): array
    {
        return parent::toArray($user) + ['chat_message' => $this->message->id];
    }

    /**
     * В Telegram — сообщение целиком; автоответ площадки — нет: человек сам только что написал. Другое сообщение площадки без
     * автора (вопрос «Берём?», `PlatformMessage`) — да: ради него оно и пишется.
     */
    public function toTelegram(): ?array
    {
        $m = $this->message;
        if ($m->author_kind !== AuthorKind::Participant && ! $m->author_id && str_contains((string) $m->text, AutoReply::MARK)) {
            return null;
        }
        $chat = $m->chat;
        $who = $this->who();
        // Общий чат покупателя с его менеджером (без машины, 12.10.2026) — просто «пишет», это не обращение к площадке.
        $title = match (true) {
            $chat->isEnquiry() && $chat->isBuyerChat() => "{$who} пишет",
            $chat->isEnquiry() => "{$who}: обращение с сайта",
            default => "{$who} пишет по {$chat->offer->titleWithYear()}",
        };

        // Покупателю пишет его менеджер — без VIN и номера: VIN покупателю по умолчанию скрыт (`Offer::vinFor`).
        $toBuyer = $chat->isBuyerChat() && $m->author_id !== null && $m->author_id === $chat->manager_id;

        // VIN строкой — только открытый у предложения и не покупателю: скрытый ушёл бы в Telegram целиком (13.10.2026).
        $lines = $toBuyer || (! $this->forStaff && ! $chat->offer?->show_vin)
            ? array_values(array_filter([$m->preview(3500), $chat->offer?->number ? Text::tag($chat->offer) : null]))
            : Text::lines($chat->offer, $m->preview(3500));

        return ['title' => $title, 'lines' => $lines, 'button' => 'Открыть чат'];
    }

    /** Сотруднику в чате площадки — CRM (там и обращения с сайта), остальным — сайт. */
    public function telegramUrl(): string
    {
        $chat = $this->message->chat;

        return ($this->forStaff && ! $chat->isBuyerChat()) || $this->toStaffInDirect() ? Surface::Crm->url('/work/chats/'.$chat->id) : parent::telegramUrl();
    }

    private function who(): string
    {
        $chat = $this->message->chat;

        return $this->forStaff ? $chat->displayName() : ($chat->manager?->shortName() ?? Chat::PLATFORM);
    }

    public function label(): string
    {
        return 'Сообщение в чате';
    }

    public function title(): string
    {
        $chat = $this->message->chat;
        $who = $this->who();

        return match (true) {
            $chat->isDirect() => "{$who} пишет",
            $chat->isEnquiry() => "{$who}: обращение с сайта",
            default => "{$who}: сообщение по № {$chat->offer->number}",
        };
    }

    public function text(): ?string
    {
        return $this->message->preview(120);
    }

    /** Чат площадки сотруднику — работа CRM: только там (адрес у него сайтовый, `Chat::path`). */
    public function surface(): ?Surface
    {
        return ($this->forStaff && ! $this->message->chat->isBuyerChat()) || $this->toStaffInDirect() ? Surface::Crm : parent::surface();
    }

    public function href(): string
    {
        return self::hrefFor($this->message->chat, $this->forStaff);
    }

    /**
     * Куда вести из уведомления и тоста: экран чата в кабинете — без хоста, чтобы установленное
     * приложение не уходило во встроенный браузер; на CRM `/chats/{id}` → `/work/chats/{id}` (`NoticeLink`).
     */
    public static function hrefFor(Chat $chat, bool $forStaff): string
    {
        if (! $chat->user_id) {
            return '/contacts';
        }

        return Chat::path($chat->id);
    }

    public function offerNumber(): ?int
    {
        return $this->message->chat->offer?->number;
    }

    /** Менеджеру и покупателю — строка на чат; сотруднику — каждое сообщение строкой. */
    public function collapses(?User $user): bool
    {
        return $user === null || $this->toManagerSide($user);
    }

    /** Пуши одного чата заменяют друг друга. */
    public function tag(): ?string
    {
        return $this->subject();
    }

    public function car(): ?string
    {
        return $this->message->chat->offer?->titleWithYear();
    }

    /** Капсулой: кто пишет и само сообщение. */
    public function brief(?User $user = null): string
    {
        return $this->who().': '.$this->message->preview(120);
    }

    public function category(): string
    {
        return 'chats';
    }

    /**
     * Дело шага сделки в чате (`requirement_id`, сообщение `StepAsk`) — тема «Сделки», критичная (19.11.2026): `YourTurnNotice`
     * при чате в Telegram не идёт, и выключенные «Чаты» не должны уносить ход сделки. Остальное — «Чаты».
     */
    public function topic(): string
    {
        return $this->message->requirement_id ? 'deals' : 'chats';
    }

    public function critical(): bool
    {
        return $this->message->requirement_id !== null;
    }

    /** Автор сообщения; автоответ площадки без автора — знак приложения. */
    public function actor(): ?User
    {
        return $this->message->author_id ? $this->message->author : null;
    }
}
