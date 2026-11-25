<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Push\WebPushChannel;
use App\Telegram\Messages\Message as TelegramMessage;
use App\Vendors\Vendor;
use App\Support\Surface;
use App\Users\User;
use App\Users\Wording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Уведомление: заголовок, текст, куда ведёт. Куда доставлять — решают настройки
 * человека (User::notification_settings): категории, почта, тихие часы.
 */
abstract class Notice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Повторы доставки (19.11.2026, владелец: «чтобы надёжно доставлялось всё, что включают»): Laravel ставит задание на
     * каждый канал отдельно, канал с временным сбоем бросает `RetryDelivery` — повторяется только он, строка ленты не
     * дублируется. Три повтора: через 1, 5 и 15 минут.
     */
    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** Повторы кончились — последней строкой журнала `failed` (видно в «Настройки → Доставка»). */
    public function failed(\Throwable $e): void
    {
        if ($e instanceof RetryDelivery && $this->id) {
            Delivery::log($this, $this->id, $e->userId, $e->channel, 'failed', 'после повторов: '.$e->detail);
        }
    }

    abstract public function title(): string;

    /** Вид словами для «Настройки → Доставка» («Интерес покупателя», «Срок этапа»): без него класс не загрузится. */
    abstract public function label(): string;

    /** Вид у фабрики с разными видами (деньги, парковка, вывоз): ключ и подпись — `variant()`. */
    private ?string $variant = null;

    private ?string $variantLabel = null;

    /** Вид для журнала доставки (`Delivery::kind`): класс, у фабрики — ещё её вид («MoneyNotice:invoice-issued»). */
    public function kind(): string
    {
        return class_basename($this).($this->variant ? ':'.$this->variant : '');
    }

    /** Фабрика называет свой вид: в статистике «Счёт выставлен», а не всё подряд «Деньги». */
    protected function variant(string $key, string $label): static
    {
        $this->variant = $key;
        $this->variantLabel = $label;

        return $this;
    }

    protected function variantLabel(): ?string
    {
        return $this->variantLabel;
    }

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

    /**
     * Метка пуша: пуши с одной меткой заменяют друг друга в центре уведомлений. С 18.11.2026 у каждого события свой пуш
     * (начальник: «все уведомления по всем событиям — в центр уведомлений»): метка — только у того, что заменяет само себя
     * (`collapses`), у чата — чат.
     */
    public function tag(): ?string
    {
        return $this->collapses(null) ? $this->subject() : null;
    }

    /**
     * Лента держит одну строку на объект (`CollapseNotices`): новое стирает прежние о нём же. С 18.11.2026 — только у того, что
     * само себя заменяет (тревога срока, утренняя сводка, пачки): иначе каждое событие — своей строкой (владелец: «каждое
     * событие»; было — «Отправить счёт» стирало «Роман берёт»).
     */
    public function collapses(?User $user): bool
    {
        return false;
    }

    /**
     * Машина, о которой уведомление, — первой строкой капсулы и строки ленты, заголовком пуша (18.11.2026, начальник:
     * «аватарка, машина в первой строке и текст во второй»). По умолчанию — машина предложения, сделки, подтверждения, счёта…
     * среди полей уведомления; без машины — null, капсула пишет заголовок.
     */
    public function car(): ?string
    {
        return $this->aboutOffer()?->titleWithYear();
    }

    /** Что случилось — коротко, за машиной: заголовок без названия машины («Роман Корабельников берёт»), иначе текст. */
    public function brief(?User $user = null): string
    {
        $title = $this->titleFor($user);
        $car = $this->car();
        if ($car === null || ! str_contains($title, $car)) {
            return $title;
        }
        $rest = trim(str_replace($car, '', $title), " \u{00A0}:—–-,");

        return $rest !== '' ? $rest : ($this->textFor($user) ?? $title);
    }

    /** Вендор — лицо уведомления, когда пишет поставщик (логотип вместо аватара). */
    public function vendor(): ?Vendor
    {
        return null;
    }

    /** Предложение среди полей уведомления: само, у сделки, подтверждения, просьбы, переоценки, счёта, оплаты. */
    protected function aboutOffer(): ?Offer
    {
        if ($offer = $this->telegramMessage?->offer()) {
            return $offer;
        }
        foreach ((array) $this as $value) {
            try {
                $offer = match (true) {
                    $value instanceof Offer => $value,
                    $value instanceof \App\Billing\Payment => $value->invoice?->deal?->offer,
                    $value instanceof \App\Billing\Invoice => $value->deal?->offer,
                    $value instanceof Model && method_exists($value, 'offer') => $value->offer,
                    $value instanceof Model && method_exists($value, 'deal') => $value->deal?->offer,
                    default => null,
                };
            } catch (\Throwable) {
                $offer = null;
            }
            if ($offer instanceof Offer) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * То же сообщение, что уходило владельцу и админам в Telegram мимо ленты (`NotifyOwner`), — Telegram-видом этого уведомления
     * (18.11.2026: «Сообщили об оплате» с кнопкой «Поступила», «Перевести Альфе»…): одно уведомление в ленту, пушем и в Telegram.
     */
    protected ?TelegramMessage $telegramMessage = null;

    public function withTelegram(?TelegramMessage $message): static
    {
        $this->telegramMessage = $message;

        return $this;
    }

    /** Telegram-вид из сообщения владельцу: заголовок, строки, кнопки решения над ссылкой. */
    protected function telegramFromMessage(): ?array
    {
        if (! $this->telegramMessage) {
            return null;
        }
        $p = $this->telegramMessage->parts();

        return ['title' => $p['title'], 'lines' => $p['lines'], 'button' => $p['link']['text'], 'keyboard' => array_chunk($p['decisions'], 2)];
    }

    /**
     * Вид пачки (`Batch`): массовое сотрудникам копится и уходит одним сводным («Срок вышел: 7 шагов»). null — сразу.
     */
    public function batchKey(): ?string
    {
        return null;
    }

    /** Строка этого уведомления в сводном: обычно название машины. */
    public function batchLine(): string
    {
        return $this->title();
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

    /**
     * Тревога (срок обязывающего горит, покупателя нет — `BindingPanicNotice`): карточка красная, а закрытая сворачивается
     * в капсулу тревоги шапки — та держится, пока причину не устранят.
     */
    public function alarm(): bool
    {
        return false;
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

    /** Уведомление о письме (письмо поставщика, «Новое из писем»): есть подписка XCAR Mail — пуш только туда (`WebPushChannel`). */
    public function aboutMail(): bool
    {
        return in_array($this->category(), ['supplier_mail', 'letters'], true);
    }

    /**
     * Уведомление о деньгах (счёт, оплата, заявка «оплатил», напоминание об оплате): есть подписка XCAR Pay — пуш только туда
     * (`WebPushChannel`, 22.11.2026).
     */
    public function aboutMoney(): bool
    {
        return $this instanceof MoneyNotice || $this instanceof InvoiceNudgeNotice;
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
        return $this->telegramFromMessage();
    }

    /** Сообщение этому человеку: у вывоза строка машины своя исполнителю и сотруднику (`TransportNotice`). */
    public function telegramFor(User $user): ?array
    {
        return $this->toTelegram();
    }

    /**
     * Вторая кнопка — «Открыть сделку» (24.11.2026, владелец: «кнопок не хватает, чтобы сделку открыть, про которую речь»): машина
     * уведомления в идущей сделке, а первая кнопка ведёт не в неё (чат, вывоз, парковка, «Оплаты»). Сотруднику сделок — CRM,
     * менеджеру — только своя сделка на сайте; остальным — нет.
     */
    public function dealUrlFor(User $user): ?string
    {
        $deal = $this->aboutOffer()?->deal;
        if (! $deal || $deal->is_demo) {
            return null;
        }
        if ($user->canEditDeals()) {
            return Surface::Crm->url('/work/deals/'.$deal->id);
        }

        return $deal->buyer_id === $user->id ? $deal->personUrl() : null;
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
    /**
     * Машина в идущей сделке — ссылка на сделку, иначе `$fallback` (22.11.2026, владелец: «неудобно, что письмо открывает,
     * все действия-то в сделке»). Для уведомлений и Telegram сотрудникам.
     */
    public static function dealPathOr(?Offer $offer, string $fallback): string
    {
        return ($deal = $offer?->deal) ? '/work/deals/'.$deal->id : $fallback;
    }

    public static function offerSubject(int $offerId): string
    {
        return 'offer:'.$offerId;
    }

    /** Куда ведёт кнопка в Telegram: абсолютный адрес как есть, путь — на сайте. */
    public function telegramUrl(): string
    {
        $href = $this->href();

        // Путь CRM, парковки и логистики — на свой хост (06.11.2026: «Открыть сделку» из «Менеджер берёт сделку» вела на
        // xcar.ru/work/deals/… — такой страницы на сайте нет).
        return str_starts_with($href, 'http') ? $href : ($this->surface() ?? Surface::Site)->url($href);
    }

    /**
     * Тема для настройки «тема × канал» менеджера и покупателя (`Categories::topics`, 19.11.2026): по умолчанию — категория;
     * своя у тех, что внутри категории живут своей строкой (счета, оценки, выдача, конец приёма).
     */
    public function topic(): string
    {
        return $this->category();
    }

    /**
     * Куда: выключенная категория — никуда; лента всегда; тихое — только в ленту; пуш — не в тихие часы;
     * почта — только включённое человеком и важное (`wantsMailFor`); Telegram — если привязан и уведомление туда просится.
     */
    public function via(User $user): array
    {
        if (Categories::siteOnly($user)) {
            return $this->viaTopic($user);
        }
        if (! $this->critical() && ! $user->wants($this->category())) {
            $this->skipped($user, 'feed', 'категория выключена');

            return [];
        }
        $via = ['database'];
        if ($this->quiet()) {
            return $via;
        }
        // Не по ответственности сотрудника (26.11.2026, `Responsibility::wants`): строкой ленты, без пуша, Telegram, почты и
        // всплывашки (`mutedFor`) — и критичное тоже (владелец про Катю: «остальные уведомления ни пуш ни в телегу ей не нужны»).
        if (! \App\Offers\Responsibility::wants($user, $this)) {
            $this->muted[$user->id] = true;
            $this->skipped($user, 'push', 'не его вкладка');
            if ($user->telegram_chat_id && $this->toTelegram() !== null) {
                $this->skipped($user, 'telegram', 'не его вкладка');
            }

            return $via;
        }
        if (! $user->quietHours()) {
            $via[] = WebPushChannel::class;
        } else {
            $this->skipped($user, 'push', 'тихие часы');
        }
        // На почту — только то, что человек сам включил (`User::wantsMailFor`, по умолчанию ничего; 06.11.2026, владелец:
        // «все ненавидят спам»), и из этого — где нужен человек, или сводка сотруднику.
        if ($user->wantsMailFor($this->category()) && ($this->critical() || $this->important() || $this instanceof BatchNotice)) {
            $via[] = SystemMailChannel::class;
        }
        // В Telegram — если он включён и эта категория там не выключена; критичное — всегда.
        if ($user->wantsTelegram($this->critical() ? null : $this->category()) && $this->toTelegram() !== null) {
            $via[] = TelegramChannel::class;
        } elseif ($user->telegram_chat_id && $this->toTelegram() !== null) {
            $this->skipped($user, 'telegram', $user->wantsTelegram() ? 'выключено в категории' : 'Telegram выключен');
        }

        return $via;
    }

    /** @var array<int, true> сотрудники, кому это не по ответственности (`via`): строкой ленты без всплывашки */
    public array $muted = [];

    public function mutedFor(object $user): bool
    {
        return isset($this->muted[$user->id ?? 0]);
    }

    /** @var list<string>|false|null вкладки, посчитанные раз на уведомление (`tabs`) */
    private array|false|null $tabsMemo = false;

    /**
     * Вкладки «Предложений», о строках которых уведомление (26.11.2026, `Responsibility::wants`): по умолчанию — где сейчас его
     * машина (`Responsibility::tabsOf`); null — уведомление не о строке вкладки (люди, выписка, сводки, личка) — как раньше всем.
     *
     * @return list<string>|null
     */
    public function tabs(): ?array
    {
        if ($this->tabsMemo === false) {
            $offer = $this->responsibleOffer();
            $this->tabsMemo = $offer ? \App\Offers\Responsibility::tabsOf($offer) : null;
        }

        return $this->tabsMemo;
    }

    /** О горящем ли (для ответственного «только за горящее»): горит обязывающий, просрочен шаг или вывоз. */
    public function urgent(): bool
    {
        $offer = $this->responsibleOffer();

        return $offer !== null && \App\Offers\Responsibility::urgentOffer($offer);
    }

    /**
     * Уведомление лично ему, а не всем сотрудникам: он менеджер этой сделки или гаража (Катя — ещё и менеджер). Ответственность
     * (`Responsibility::wants`) такое не глушит.
     */
    public function personalTo(User $user): bool
    {
        $offer = $this->responsibleOffer();

        return $offer !== null && (\App\Offers\Deal::active()->where('offer_id', $offer->id)->where('buyer_id', $user->id)->exists()
            || \App\Garage\Car::where('offer_id', $offer->id)->where('manager_id', $user->id)->exists());
    }

    /** Машина уведомления для ответственности: среди полей или по номеру (`offerNumber` — у денег машины среди полей нет). */
    private function responsibleOffer(): ?Offer
    {
        return $this->aboutOffer() ?? (($n = $this->offerNumber()) ? Offer::where('number', $n)->first() : null);
    }

    /**
     * Менеджеру и покупателю — «тема × канал» (`User::wantsVia`): лента всегда, пуш (кроме тихих часов) и Telegram — как
     * включено у темы; почта — прежним правилом.
     */
    protected function viaTopic(User $user): array
    {
        $via = ['database'];
        if ($this->quiet()) {
            return $via;
        }
        $topic = $this->topic();
        $critical = $this->critical();
        if (! $user->wantsVia($topic, 'push', $critical)) {
            $this->skipped($user, 'push', 'выключено в теме');
        } elseif ($user->quietHours()) {
            $this->skipped($user, 'push', 'тихие часы');
        } else {
            $via[] = WebPushChannel::class;
        }
        if ($user->wantsMailFor($this->category()) && ($critical || $this->important())) {
            $via[] = SystemMailChannel::class;
        }
        if ($user->telegram_chat_id && $this->toTelegram() !== null) {
            if (! $user->wantsTelegram()) {
                $this->skipped($user, 'telegram', 'Telegram выключен');
            } elseif (! $user->wantsVia($topic, 'telegram', $critical)) {
                $this->skipped($user, 'telegram', 'выключено в теме');
            } else {
                $via[] = TelegramChannel::class;
            }
        }

        return $via;
    }

    /**
     * Не ушло по выбору человека — тоже строкой журнала (18.11.2026: «почему ему не пришло» — видно в «Настройки → Доставка»):
     * статус `off`, в числа «отправлено» не входит. Laravel зовёт `via` до того, как у уведомления есть id, — у строки свой.
     */
    private function skipped(User $user, string $channel, string $why): void
    {
        Delivery::log($this, $this->id ?? (string) Str::uuid(), $user->id, $channel, 'off', $why);
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

    /**
     * Строка ленты. `car`, `brief`, `actor`, `vendor` — капсулой (18.11.2026): машина, коротко что, лицо (сотрудник площадки
     * менеджеру и покупателю — без лица, как на карточке, `Live\Banner`).
     */
    public function toArray(User $user): array
    {
        $actor = $this->actor();
        $actor = $actor?->isStaff() && ! $user->isStaff() ? null : $actor;

        return ['title' => $this->titleFor($user), 'text' => $this->textFor($user), 'href' => $this->href(), 'offer' => $this->offerNumber(), 'subject' => $this->subject()]
            + ($this->surface() ? ['surface' => $this->surface()->value] : [])
            + ($this->alarm() ? ['alarm' => true] : [])
            + (($car = $this->car()) ? ['car' => $car, 'brief' => $this->brief($user)] : [])
            + ($actor ? ['actor' => $actor->id] : [])
            + (($vendor = $this->vendor()) ? ['vendor' => $vendor->id] : [])
            + ($this->important() ? ['important' => true] : [])
            + ['icon' => $this->icon()];
    }

    /**
     * Уведомление письмом (`SystemMailChannel`, общий вид `emails.layout`): заголовок, строка текста, «Открыть» — на хост
     * своего приложения, как кнопка Telegram, и «Не присылать на почту» (ещё и `List-Unsubscribe`).
     */
    public function toSystemMail(User $user): \Symfony\Component\Mime\Email
    {
        $html = view('emails.notice', [
            'title' => $this->titleFor($user), 'subtitle' => $this->textFor($user), 'preheader' => $this->textFor($user), 'compact' => true,
            'button' => ['text' => 'Открыть', 'url' => NoticeLink::tracked($this->telegramUrl(), $this, 'mail')], 'unsubscribe' => $user->unsubscribeUrl(),
        ])->render();

        return \App\Mail\SystemMail::make((string) $user->email, $user->name, $this->titleFor($user), $html, ['unsubscribe' => $user->unsubscribeUrl()]);
    }
}
