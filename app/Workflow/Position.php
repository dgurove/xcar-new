<?php

namespace App\Workflow;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public static function gapAction(?string $gap, \App\Offers\Deal $deal): array
    {
        $money = \App\Support\Surface::Crm->url('/work/deals/'.$deal->id.'#money');

        return match ($gap) {
            'share' => ['title' => 'Впишите нашу долю', 'button' => 'Вписать нашу долю', 'url' => $money],
            'commission' => ['title' => 'Назначьте вознаграждение', 'button' => 'Назначить вознаграждение', 'url' => $money],
            // Реквизиты продавцов правятся на парковке («Реквизиты»), Р.О.С. — первой строкой «мы».
            'ros_requisites' => ['title' => 'Заполните реквизиты Р.О.С.', 'button' => 'Заполнить реквизиты Р.О.С.', 'url' => \App\Support\Surface::Park->url('/money/parties')],
            default => ['title' => 'Выставите счёт', 'button' => 'Выставить счёт', 'url' => \App\Support\Surface::Crm->url('/work/invoices/new?offer='.$deal->offer->number)],
        };
    }

    /** Идущая сделка оффера этой позиции. */
    public function deal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Offers\Deal::class, 'offer_id', 'offer_id')->where('state', \App\Offers\DealState::Active);
    }

    /** Позиции, где ход за этим участником, — тем же правилом, что `waitsFor()`, в SQL. */
    public function scopeWaiting($q, WaitsFor $who)
    {
        return $q->where(fn ($w) => $w->where('offer_positions.waits_for', $who->value)
            ->orWhere(fn ($x) => $x->whereNull('offer_positions.waits_for')->whereHas('stage', fn ($s) => $s->where('waits_for', $who))));
    }
}
