<?php

namespace App\Notifications;

use App\Push\WebPushChannel;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Уведомление: заголовок, текст, куда ведёт. Куда доставлять — решают настройки
 * человека (User::notification_settings): категории, почта, тихие часы.
 */
abstract class Notice extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function title(): string;

    public function text(): ?string
    {
        return null;
    }

    abstract public function href(): string;

    public function offerNumber(): ?int
    {
        return null;
    }

    /**
     * Объект, о котором уведомление, — путь без хоста (с запросом, если он и называет объект). В ленте у человека одна
     * строка на объект: новое уведомление заменяет прежние (CollapseNotices), а открытый объект гасит его
     * (ReadNoticesOnVisit). Сделочные отдают `/deals/{id}`, чтобы ход, сроки и деньги по сделке были одной строкой.
     */
    public function subject(): string
    {
        $href = $this->href();
        $path = parse_url($href, PHP_URL_PATH) ?: '/';
        $query = parse_url($href, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }

    /** Метка пуша: пуши одного объекта заменяют друг друга. */
    public function tag(): ?string
    {
        return $this->subject();
    }

    /** Тихое — только обновляет строку объекта в ленте: без пуша, почты и Telegram (шаг сделки, где менеджер не нужен). */
    public function quiet(): bool
    {
        return false;
    }

    /**
     * Важное — то, где нужен сам человек: карточка в приложении со звуком и висит, пока её не нажмут или не смахнут,
     * системный пуш тоже ждёт нажатия. По умолчанию — критичное и то, что просится в Telegram (туда идёт только то, что
     * требует человека). Остальное — та же карточка, тихо, уходит сама.
     */
    public function important(): bool
    {
        return ! $this->quiet() && ($this->critical() || $this->toTelegram() !== null);
    }

    /** Кто совершил действие — его аватар на карточке; нет человека — иконка (`icon`). */
    public function actor(): ?User
    {
        return null;
    }

    /** Иконка карточки без человека — из `x-ui.icon`, по категории. */
    public function icon(): string
    {
        return match ($this->category()) {
            'bids', 'deals' => 'deal',
            'money' => 'wallet',
            'park' => 'park',
            'offers', 'purchases', 'interest' => 'car',
            'letters' => 'mail',
            'people' => 'user',
            'chats' => 'chat',
            default => 'bell',
        };
    }

    /** Приложение, где уведомление живёт: парковка — отдельно от CRM и сайта (владелец, 05.10.2026), остальное — null. */
    public function surface(): ?Surface
    {
        return $this->category() === 'park' ? Surface::Park : null;
    }

    /** Категория для настроек (Categories): что человек может выключить. */
    public function category(): string
    {
        return 'other';
    }

    /** Критичное приходит всегда: ответ на подтверждение, сделки, выбор цены. */
    public function critical(): bool
    {
        return false;
    }

    /**
     * Сообщение в привязанный Telegram: заголовок жирным, строки, подпись кнопки-ссылки на href. null — туда не шлём:
     * в Telegram только то, что требует человека — принятое подтверждение, его ход и сроки, деньги, сообщения чатов. Без длинных тире:
     * пример в шторке подключения (Telegram\Preview) выглядит так же.
     *
     * @return array{title: string, lines: list<?string>, button: string}|null
     */
    public function toTelegram(): ?array
    {
        return null;
    }

    /**
     * Предмет сообщения в Telegram (`candidate:12`): ушёл предмет — сообщение удаляется у всех (`telegram_messages.subject`).
     * null — сообщение остаётся, как было.
     */
    public function telegramSubject(): ?string
    {
        return null;
    }

    /** Куда ведёт кнопка в Telegram: абсолютный адрес как есть, путь — на сайте. */
    public function telegramUrl(): string
    {
        $href = $this->href();

        return str_starts_with($href, 'http') ? $href : Surface::Site->url($href);
    }

    /**
     * Куда: выключенная категория — никуда; лента всегда; тихое — только в ленту; пуш — не в тихие часы;
     * почта — если есть адрес и не выключена; Telegram — если привязан и уведомление туда просится.
     */
    public function via(User $user): array
    {
        if (! $this->critical() && ! $user->wants($this->category())) {
            return [];
        }
        $via = ['database'];
        if ($this->quiet()) {
            return $via;
        }
        if (! $user->quietHours()) {
            $via[] = WebPushChannel::class;
        }
        if ($user->email && $user->wantsMail()) {
            // Уведомления стоянки — через её ящик, если он есть; остальное — системный мейлер.
            $via[] = $this->category() === 'park' && MailboxChannel::account() ? MailboxChannel::class : 'mail';
        }
        // В Telegram — если он включён и эта категория там не выключена; критичное — всегда.
        if ($user->wantsTelegram($this->critical() ? null : $this->category()) && $this->toTelegram() !== null) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    public function toArray(User $user): array
    {
        return ['title' => $this->title(), 'text' => $this->text(), 'href' => $this->href(), 'offer' => $this->offerNumber(), 'subject' => $this->subject()]
            + ($this->surface() ? ['surface' => $this->surface()->value] : []);
    }

    public function toMail(User $user): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting($user->name.',')->line($this->title());
        if ($this->text()) {
            $mail->line($this->text());
        }

        return $mail->action('Открыть', url($this->href()))->salutation(config('app.name'))
            ->line(new HtmlString('<a href="'.e($user->unsubscribeUrl()).'" style="color:#808080">Не присылать на почту</a>'));
    }
}
