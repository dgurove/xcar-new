<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Support\Surface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Где оффер стоит на ветке маршрута. Чей ход — `waitsFor()`: этап задаёт его, позиция может перебить (`waits_for`) —
 * этап оплаты без счёта ждёт нас, а не менеджера (05.10.2026: обе стороны ждали друг друга).
 */
#[Fillable(['offer_id', 'track', 'stage_id', 'waits_for', 'entered_at', 'block_entered_at', 'deadline_at', 'reminded_at', 'overdue_at', 'payload'])]
class Position extends Model
{
    protected $table = 'offer_positions';

    protected function casts(): array
    {
        return [
            'track' => Track::class,
            'waits_for' => WaitsFor::class,
            'entered_at' => 'datetime',
            'block_entered_at' => 'datetime',
            'deadline_at' => 'datetime',
            'reminded_at' => 'datetime',
            'overdue_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function isOverdue(): bool
    {
        return $this->deadline_at?->isPast() ?? false;
    }

    /** Чей ход сейчас: свой у позиции, иначе этапа. Читать только так, не `stage->waits_for`. */
    public function waitsFor(): WaitsFor
    {
        return $this->waits_for ?? $this->stage->waits_for;
    }

    /**
     * Письмо поставщику на этапе с письмом (`Stage::isLetterStep`, 11.10.2026: начальник не понимал, ушло письмо или
     * нет): `unsent` — ход наш, письма нет; `sent` — ушло (`payload.letter_at`), ждём ответа, срок от письма;
     * `replied` — поставщик ответил (`payload.reply_at`), ход наш — отметить исход. Не такой этап — null.
     */
    public function letterStatus(): ?string
    {
        if ($this->track !== Track::Sale || ! $this->stage->isLetterStep()) {
            return null;
        }

        // Доставка (18.10.2026): отказ сервера после «ушло» и неуспех с нашей стороны — тоже наш ход.
        return match (true) {
            isset($this->payload['reply_at']) => 'replied',
            isset($this->payload['letter_bounced']) => 'bounced',
            isset($this->payload['letter_at']) => 'sent',
            isset($this->payload['letter_failed']) => 'failed',
            $this->waits_for === WaitsFor::Us => 'unsent',
            default => null,
        };
    }

    /**
     * Нужно ли наше действие (27.10.2026, владелец: «рыжим только если от нас конкретно что-то требуется»): ход наш,
     * письмо поставщику не ушло, не доставлено или он ответил. Чужая просрочка — не наш ход. SQL-зеркало — `scopeNeedsUs`.
     */
    public function needsUs(): bool
    {
        return ($this->waitsFor() === WaitsFor::Us && ! $this->carried()) || in_array($this->letterStatus(), ['unsent', 'failed', 'bounced', 'replied'], true)
            || $this->consentLate() || $this->repricePending() || $this->priceAskLate() !== null;
    }

    /**
     * Менеджер не ответил про выросшую закупочную к сроку (28.10.2026, `RepriceAsk`) — горит у нас: позвонить или ответить
     * за него. Только сделка: подтверждения в «Сделках» нет.
     */
    public function priceAskLate(): ?\App\Offers\RepriceAsk
    {
        if ($this->track !== Track::Sale) {
            return null;
        }

        return once(fn () => \App\Offers\RepriceAsk::pending()->where('offer_id', $this->offer_id)->whereNotNull('deal_id')
            ->where('due_at', '<', now())->whereHas('deal', fn ($q) => $q->where('state', \App\Offers\DealState::Active))->first());
    }

    /** Ждёт решения переоценка закупочной (28.10.2026, `Offers\Reprice`) — наш ход, что бы ни было на этапе. */
    public function repricePending(): bool
    {
        return $this->track === Track::Sale && $this->offer?->pendingReprice !== null;
    }

    /**
     * Шаг «Менеджер забирает автомобиль», а везёт логистика (`SyncHandover::turn` ставит ход наш, чтобы у менеджера не было
     * часов «Заберите»). В CRM делать нечего — шаг пройдёт сам по «Привёз» (27.10.2026, владелец: Volvo «идёт 8 д» в «Ваш ход»).
     */
    public function carried(): bool
    {
        return $this->waits_for === WaitsFor::Us && $this->track === Track::Sale && $this->stage->isPickupStep();
    }

    /**
     * Что сделать нам — для «Ваш ход» в «Сделках» (27.10.2026, владелец: «не видно, что нужно сделать, а что просто
     * просрочено»): группа (`key`, `title` глаголом, `rank` — порядок групп), с какого момента ждёт (`since`) и кнопка
     * строки (`letters` — окно писем, `href` — ссылка, `tel`). Заявка Каркаде — кнопка у группы, одна на вендора.
     * Не наш ход — null. @return array{key: string, title: string, rank: int, since: ?Carbon, button: ?array}|null
     */
    public function todo(): ?array
    {
        if (! $this->needsUs()) {
            return null;
        }
        $offer = $this->offer;
        $since = $this->block_entered_at ?? $this->entered_at;
        $letter = $this->awaitsInvoice() ? null : $this->letterStatus();
        $todo = fn (string $key, string $title, int $rank, ?Carbon $at = null, ?array $button = null) => ['key' => $key, 'title' => $title, 'rank' => $rank, 'since' => $at ?? $since, 'button' => $button];
        $write = fn (string $label) => ($url = $offer->vendorLetterUrl($this->stage)) ? ['label' => $label, 'letters' => $url] : null;

        if ($this->awaitsInvoice()) {
            $gap = $this->gap();
            $action = self::gapAction($gap, $this->deal);

            return $todo('gap:'.$gap, $action['button'], 2, button: ['label' => $action['button'], 'href' => $action['url']]);
        }

        if ($ask = $this->priceAskLate()) {
            return $todo('price-ask', 'Менеджер не ответил про закупочную', 0, $ask->due_at,
                ($phone = $this->deal?->buyer?->phone) ? ['label' => 'Позвонить', 'tel' => $phone] : ['label' => 'Открыть', 'href' => '/offers/'.$offer->number]);
        }

        if ($this->repricePending()) {
            return $todo('reprice', 'Разобрать переоценку', 0, $offer->pendingReprice->created_at, ['label' => 'Открыть', 'href' => '/offers/'.$offer->number]);
        }

        return match (true) {
            $letter === 'replied' => $todo('reply', 'Разобрать ответ поставщика', 0, $this->replyAt(), ['label' => 'Открыть письмо', 'letters' => '/offers/'.$offer->number.'/letters']),
            $letter === 'bounced' => $todo('bounced', 'Письмо поставщику не дошло', 1, button: $write('Написать снова')),
            in_array($letter, ['unsent', 'failed'], true) => $todo('letter', 'Написать поставщику', 1, button: $write('Написать')),
            $this->consentLate() => $todo('consent', 'Менеджер не ответил, берёт ли', 3, $this->deadline_at,
                ($phone = $this->deal?->buyer?->phone) ? ['label' => 'Позвонить', 'tel' => $phone] : null),
            $this->stage->isRequestStep() => $todo('request:'.$offer->vendor_id, 'Отправить заявку поставщику', 4),
            default => $todo('stage:'.$this->stage->name, $this->stage->name, 5),
        };
    }

    /**
     * Менеджер не дал согласия в срок (27.10.2026, владелец: «горит у нас, решаем сами» — позвонить, отдать резерву, отменить).
     * Ход остаётся его: кнопки «Беру в работу» / «Я передумал» живы, у него — красным «просрочено».
     */
    public function consentLate(): bool
    {
        return $this->overdue_at !== null && $this->waitsFor() === WaitsFor::Manager && $this->stage->asksConsent();
    }

    /** Служебные ключи позиции (письмо поставщику) — не поля этапа: на экране их нет. */
    public const LETTER_KEYS = ['letter_id', 'letter_at', 'reply_at', 'letter_failed', 'letter_bounced'];

    /** Поля этапа, что вписали люди (адрес, дата вывоза…), — без служебных ключей. */
    public function fields(): array
    {
        return array_diff_key($this->payload ?? [], array_flip(self::LETTER_KEYS));
    }

    public function letterAt(): ?Carbon
    {
        return isset($this->payload['letter_at']) ? Carbon::parse($this->payload['letter_at']) : null;
    }

    public function replyAt(): ?Carbon
    {
        return isset($this->payload['reply_at']) ? Carbon::parse($this->payload['reply_at']) : null;
    }

    /** Этап оплаты, а счёта ещё нет: наш ход — выставить счёт. */
    public function awaitsInvoice(): bool
    {
        return $this->waits_for === WaitsFor::Us && $this->stage->isPayStep();
    }

    /**
     * Чего не хватает этапу оплаты, когда ход наш: `share` — гаражной не вписана наша доля, `invoice` — счёт не встал
     * сам (`Deal::invoiceGap`). «Укажите покупателя» у ПРАЙМ — ход менеджера, сюда не попадает.
     */
    public function gap(): ?string
    {
        if (! $this->awaitsInvoice()) {
            return null;
        }
        // Через связь: списки, где часы стоят в каждой строке, грузят её разом (`with('deal')`).
        $gap = $this->deal?->invoiceGap();

        return in_array($gap, ['share', 'commission', 'ros_requisites'], true) ? $gap : 'invoice';
    }

    /**
     * Что сделать нам, когда счёт не встал сам: заголовок уведомления, подпись кнопки и куда она ведёт (CRM). Одно место
     * для ленты, Telegram, часов и пути. @return array{title: string, button: string, url: string}
     */
    public static function gapAction(?string $gap, Deal $deal): array
    {
        $money = Surface::Crm->url('/work/deals/'.$deal->id.'#money');

        return match ($gap) {
            'share' => ['title' => 'Впишите нашу долю', 'button' => 'Вписать нашу долю', 'url' => $money],
            'commission' => ['title' => 'Назначьте вознаграждение', 'button' => 'Назначить вознаграждение', 'url' => $money],
            // Реквизиты продавцов правятся на парковке («Реквизиты»), Р.О.С. — первой строкой «мы».
            'ros_requisites' => ['title' => 'Заполните реквизиты Р.О.С.', 'button' => 'Заполнить реквизиты Р.О.С.', 'url' => Surface::Park->url('/money/parties')],
            default => ['title' => 'Выставите счёт', 'button' => 'Выставить счёт', 'url' => Surface::Crm->url('/work/invoices/new?offer='.$deal->offer->number)],
        };
    }

    /** Идущая сделка оффера этой позиции. */
    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class, 'offer_id', 'offer_id')->where('state', DealState::Active);
    }

    /** `needsUs()` одним SQL-условием над `offer_positions` — для скоупа, сортировок и подзапросов. */
    // coalesce — пустой payload даёт null, и «не наш ход» (`whereNot`) терял такие позиции. Подписи согласия — `Outcome::TAKES`.
    // Ход наш на шаге получения под логистикой (`carried`) — не наш: этап с исходом менеджера «Автомобиль забрал».
    // Метки письма — только на этапе письма (`Stage::isLetterStep`), «менеджер не ответил» — только пока ход его
    // (`consentLate`): без этого число пилюли «Ваш ход» расходилось со строками (27.10.2026). Ждущая переоценка — `repricePending`.
    public const NEEDS_US_SQL = "coalesce((coalesce(offer_positions.waits_for, (select s.waits_for from workflow_stages s where s.id = offer_positions.stage_id)) = 'us'
            and not (coalesce(offer_positions.waits_for, '') = 'us' and exists (select 1 from workflow_exits x where x.stage_id = offer_positions.stage_id
                and x.actor = 'manager' and lower(x.label) = 'автомобиль забрал')))
        or (exists (select 1 from workflow_stages s where s.id = offer_positions.stage_id and s.template_id is not null and s.waits_for = 'supplier')
            and (offer_positions.payload ?? 'reply_at' or offer_positions.payload ?? 'letter_bounced'
                or (offer_positions.payload ?? 'letter_failed' and not offer_positions.payload ?? 'letter_at')))
        or (offer_positions.overdue_at is not null
            and coalesce(offer_positions.waits_for, (select s.waits_for from workflow_stages s where s.id = offer_positions.stage_id)) = 'manager'
            and exists (select 1 from workflow_exits x where x.stage_id = offer_positions.stage_id
                and x.actor = 'manager' and lower(x.label) in ('беру в работу', 'покупаю')))
        or (offer_positions.track = 'sale' and exists (select 1 from offer_reprices r where r.offer_id = offer_positions.offer_id and r.state = 'pending'))
        or (offer_positions.track = 'sale' and exists (select 1 from reprice_asks a join deals d on d.id = a.deal_id
            where a.offer_id = offer_positions.offer_id and a.state = 'pending' and a.due_at < now() and d.state = 'active')), false)";

    /** Позиции, где нужно наше действие, — `needsUs()` в SQL. */
    public function scopeNeedsUs($q)
    {
        return $q->whereRaw(self::NEEDS_US_SQL);
    }

    /** Позиции, где ход за этим участником, — тем же правилом, что `waitsFor()`, в SQL. */
    public function scopeWaiting($q, WaitsFor $who)
    {
        return $q->where(fn ($w) => $w->where('offer_positions.waits_for', $who->value)
            ->orWhere(fn ($x) => $x->whereNull('offer_positions.waits_for')->whereHas('stage', fn ($s) => $s->where('waits_for', $who))));
    }
}
