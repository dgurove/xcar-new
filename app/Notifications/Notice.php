<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Push\WebPushChannel;
use App\Support\Surface;
use App\Users\User;
use App\Users\Wording;
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
            'park_money' => 'wallet',
            'logistics' => 'cat-car',
            'offers', 'purchases', 'interest' => 'cat-car',
            'letters' => 'mail',
            'people' => 'user',
            'chats' => 'chat',
            default => 'bell',
        };
    }

    /**
     * Приложение, где уведомление живёт: парковка и логистика — отдельно от CRM и сайта (владелец, 05.10.2026); то, что ведёт в CRM
     * (адрес CRM, `/work/…`, `/settings/…`), — только в CRM (08.10.2026, владелец: «зачем их в xcar показывать… открыл
     * приложение xcar, а тебя в CRM перекидывает»); остальное — null, сайт и CRM.
     */
    public function surface(): ?Surface
    {
        if (str_starts_with($this->category(), 'park')) {
            return Surface::Park;
        }
        if ($this->category() === 'logistics') {
            return Surface::Logistics;
        }

        return self::leadsToCrm($this->href()) ? Surface::Crm : null;
    }

    /** Адрес ведёт в CRM: абсолютный на её хост или путь, которого на сайте нет. */
    public static function leadsToCrm(string $href): bool
    {
        if (str_starts_with($href, 'http')) {
            return parse_url($href, PHP_URL_HOST) === Surface::Crm->host();
        }

        return str_starts_with($href, '/work/') || str_starts_with($href, '/settings/');
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
        // Сделочные (`/deals/{id}`) — предмет сделки: отменили её — сообщения уходят (`RetractDealNotices`).
        return preg_match('#^/deals/(\d+)$#', $this->subject(), $m) ? self::dealSubject((int) $m[1]) : null;
    }

    public static function dealSubject(int $dealId): string
    {
        return 'deal:'.$dealId;
    }

    /**
     * Уведомление о машине — только тому, кому она видна в момент доставки: очередь шлёт позже, чем уведомление
     * поставили, и за это время волну могли сдвинуть или машину снять (13.10.2026).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $offer = $this->visibleOffer();

        return ! $offer || ! $notifiable instanceof User || $offer->fresh()?->isVisibleTo($notifiable) === true;
    }

    /** Машина, которую должен видеть получатель; null — уведомление не о машине целиком. */
    protected function visibleOffer(): ?Offer
    {
        return null;
    }

    /** Предмет сообщений о предложении: ушло от человека (снято, сдвинули волну) — `RetractOfferNotices` их удалит. */
    public static function offerSubject(int $offerId): string
    {
        return 'offer:'.$offerId;
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
            $via[] = str_starts_with($this->category(), 'park') && MailboxChannel::account() ? MailboxChannel::class : 'mail';
        }
        // В Telegram — если он включён и эта категория там не выключена; критичное — всегда.
        if ($user->wantsTelegram($this->critical() ? null : $this->category()) && $this->toTelegram() !== null) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    /** Текст из маршрута сделки (этап, просьба): супер-покупателю его слова «менеджер» переводятся (`Users\Wording`). */
    public function speaksRoute(): bool
    {
        return false;
    }

    /** Заголовок для этого человека: в уведомлении о шаге сделки супер-покупателю — без слова «менеджер». */
    public function titleFor(?User $user): string
    {
        return (string) self::glue($this->speaksRoute() ? (string) Wording::for($user, $this->title()) : $this->title());
    }

    public function textFor(?User $user): ?string
    {
        return self::glue($this->speaksRoute() ? Wording::for($user, $this->text()) : $this->text());
    }

    /**
     * Неразрывные пробелы там, где перенос рвёт смысл: «№» с номером, разряды суммы, сумма с «₽» (11.10.2026: в карточке
     * уведомления «№» оставался в конце строки, а номер уезжал вниз). Зовут и строки ленты — старые заголовки в базе.
     */
    public static function glue(?string $text): ?string
    {
        return $text === null ? null : preg_replace(['/№ (?=\S)/u', '/(?<=\d) (?=\d{3}(?!\d))/u', '/ ₽/u'], ["№\u{00A0}", "\u{00A0}", "\u{00A0}₽"], $text);
    }

    public function toArray(User $user): array
    {
        return ['title' => $this->titleFor($user), 'text' => $this->textFor($user), 'href' => $this->href(), 'offer' => $this->offerNumber(), 'subject' => $this->subject()]
            + ($this->surface() ? ['surface' => $this->surface()->value] : []);
    }

    public function toMail(User $user): MailMessage
    {
        $mail = (new MailMessage)->subject($this->titleFor($user))->greeting($user->name.',')->line($this->titleFor($user));
        if ($this->text()) {
            $mail->line($this->textFor($user));
        }

        return $mail->action('Открыть', url($this->href()))->salutation(config('app.name'))
            ->line(new HtmlString('<a href="'.e($user->unsubscribeUrl()).'" style="color:#808080">Не присылать на почту</a>'));
    }
}
