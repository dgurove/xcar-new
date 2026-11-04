<?php

namespace App\Offers;

use App\Support\Money;

/**
 * Переоценка в идущей сделке — свой шаг пути (04.11.2026, владелец про X6 № 2610041185: «почему согласование с поставщиком,
 * если на самом деле идёт переоценка — пришло сообщение от страховой, мы ей так и не ответили, Владислав не нажал
 * „согласен“, другие менеджеры ещё не подтвердили»). Пока он не пройден, этап маршрута ждёт впереди (`x-route.path`).
 *
 * Три подпункта, каждый `done` | `now` | `wait`:
 * - **цена** — переоценку разобрали (`ApplyReprice`);
 * - **покупатель** — менеджер сделки согласен (`RepriceAsk`), по сделке решили без вопроса или, пока он думает, машину
 *   показывают всем (`ShowDealToAll`) и кто-то подтвердил резервом;
 * - **ответ** поставщику — только у переоценки письмом: отправили ответ на письмо или «Ответили не письмом»
 *   (`Reprice::answered_at`). Отвечаем, когда есть покупатель (владелец, 04.11.2026), до того — `wait`.
 *
 * Шаг есть, пока не пройден хоть один подпункт.
 */
final class RepriceFlow
{
    public const DONE = 'done';

    public const NOW = 'now';

    public const WAIT = 'wait';

    private function __construct(
        public readonly Offer $offer,
        public readonly Deal $deal,
        public readonly Reprice $reprice,
        public readonly ?RepriceAsk $ask,
        public readonly int $reserves,
    ) {}

    /** Переоценку этого предложения показывает шаг пути — плашка не нужна (`x-offer.reprice sheet-only`). */
    public static function inRoute(Offer $offer): bool
    {
        $deal = $offer->deal;

        return $deal && $deal->isActive() && ! $deal->isGarage() && $offer->positions->isNotEmpty();
    }

    public static function for(Offer $offer): ?self
    {
        $deal = $offer->deal;
        if (! $deal || ! $deal->isActive() || $deal->isGarage()) {
            return null;
        }
        // Ждущая — она; иначе последняя разобранная после начала сделки (до сделки переоценка её не касалась).
        $reprice = $offer->pendingReprice
            ?? Reprice::where('offer_id', $offer->id)->where('state', Reprice::APPLIED)->where('created_at', '>=', $deal->created_at)->latest('id')->first();
        if (! $reprice) {
            return null;
        }
        $ask = $reprice->isPending() ? null : RepriceAsk::where('deal_id', $deal->id)->where('reprice_id', $reprice->id)
            ->whereIn('state', [RepriceAsk::PENDING, RepriceAsk::AGREED])->latest('id')->with('user')->first();
        $reserves = $offer->deal_show_all_at
            ? $offer->bids()->where('state', BidState::Active)->where('created_at', '>=', $offer->deal_show_all_at)->count()
            : 0;
        $flow = new self($offer, $deal, $reprice->loadMissing(['message', 'decider']), $ask, $reserves);

        return $flow->price() === self::DONE && $flow->buyer() === self::DONE && in_array($flow->answer(), [self::DONE, null], true) ? null : $flow;
    }

    public function price(): string
    {
        return $this->reprice->isPending() ? self::NOW : self::DONE;
    }

    /** Покупатель по новой цене: менеджер сделки согласен (или решили без вопроса) либо подтвердил другой во время показа. */
    public function buyer(): string
    {
        return match (true) {
            $this->reprice->isPending() => self::WAIT,
            $this->ask?->isPending() && $this->reserves === 0 => self::NOW,
            default => self::DONE,
        };
    }

    /** Ответ поставщику: null — переоценка не письмом, отвечать некому. */
    public function answer(): ?string
    {
        return match (true) {
            $this->reprice->source !== 'letter' => null,
            $this->reprice->answered_at !== null => self::DONE,
            $this->buyer() === self::DONE => self::NOW,
            default => self::WAIT,
        };
    }

    /** На какую цену соглашаемся в ответе: у «закупочной от оценочной» (Альфа) поставщик называл оценочную. */
    public function agreedAmount(): int
    {
        return $this->reprice->value ?? $this->reprice->to_price;
    }

    /** Готовый текст ответа поставщику. */
    public function replyText(): string
    {
        return 'Добрый день! Согласны на новую стоимость '.Money::plain($this->agreedAmount(), sign: false).' руб.';
    }

    /** Окно писем на письме переоценки с редактором ответа и готовым текстом. */
    public function replyUrl(): ?string
    {
        $m = $this->reprice->message;

        return $m ? '/offers/'.$this->offer->number.'/letters?'.http_build_query(['reply' => 1, 'at' => $m->id, 'text' => $this->replyText()]) : null;
    }

    public function showsToAll(): bool
    {
        return $this->offer->showsDealAsOpen();
    }
}
