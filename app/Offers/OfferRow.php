<?php

namespace App\Offers;

use App\Http\Admin\OfferController;
use App\Offers\Actions\ChangeOfferState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * Предложение строкой CRM — что о нём сказать в строке таблицы (`x-offer.table-row`) и в плитке (`x-offer.crm-card`), одним
 * расчётом (11.11.2026: сорок строк вычислений были скопированы в оба вида и разошлись — плитка после продажи писала
 * «Сделки», таблица — этап и менеджера; плитка считала гаражные подтверждения числом, таблица — «1 в гараж»). Вид решает
 * только, где и каким элементом это стоит, по образцу `DealLine` / `Garage\CarLine`.
 */
final class OfferRow
{
    public readonly bool $admin;

    public readonly bool $draft;

    /** Черновик без цены продажи. */
    public readonly bool $unpriced;

    /** «Оценить» — дело админа, у догружающегося черновика — после. */
    public readonly bool $rate;

    /** @var Collection<int, Estimate> корректировки менеджеров у оценённого черновика (`x-offer.correction-chip`) */
    public readonly Collection $corrections;

    /** Идёт приём — часы до конца (модератору их нет). */
    public readonly bool $timer;

    /** Где предложение словом: после продажи — этап сделки (`DealLine`), иначе вкладка (`Offer::crmTab`). */
    public readonly string $stateWord;

    public readonly string $tone;

    public readonly ?DealLine $line;

    /** Подтверждений (в галерее — интереса) всего. */
    public readonly int $count;

    /** Из них гаражных (без суммы) и с ценой. */
    public readonly int $garageBids;

    public readonly int $priceBids;

    /** «2 подтв., 1 в гараж» / «интерес 3»: гаражные без суммы — словом (владелец 05.10.2026: «непонятно, что такое 1»). */
    public readonly string $countWord;

    /** Поставлено в слот — «выйдет сегодня в 16:00». */
    public readonly ?string $slot;

    /** @var list<string> чего не хватает для продажи («нет фото») — у черновика с галочкой выбора */
    public readonly array $missing;

    /** У человека по строке есть дело (`OfferController::todo`): по таким карточка идёт «Дальше». */
    public readonly bool $todo;

    public readonly ?Vendor $vendor;

    /** VIN не дают — красным у названия, пока продажа жива; у догружающегося черновика — после. */
    public readonly bool $noVin;

    private function __construct(public readonly Offer $offer, ?User $user, bool $gallery, bool $checkable, bool $busy)
    {
        $this->admin = (bool) $user?->canManageCrm();
        $this->draft = $offer->state === OfferState::Draft;
        $this->unpriced = $this->draft && ! $offer->asking_price;
        $this->rate = $this->unpriced && $this->admin && ! $busy;
        $this->corrections = ! $this->unpriced && $this->draft && $this->admin && $offer->relationLoaded('openEstimates')
            ? $offer->openEstimates->filter->isCorrection()->values() : collect();
        $left = $gallery || ! $this->admin || ! $offer->state->acceptsBids() ? null : $offer->secondsLeft();
        $this->timer = $left !== null && $left > 0;
        $this->line = $this->admin && in_array($offer->state, [OfferState::Sold, OfferState::Delivered, OfferState::Garage, OfferState::Cancelled], true)
            ? DealLine::forOffer($offer) : null;
        $this->stateWord = $this->line?->word ?? $offer->crmTab();
        $this->tone = $this->line?->tone ?? $offer->state->textTone();
        $this->count = ! $this->admin ? 0 : ($gallery ? (int) ($offer->interests_count ?? 0) : (int) ($offer->active_bids_count ?? 0));
        $this->garageBids = $gallery ? 0 : min($this->count, (int) ($offer->garage_bids_count ?? 0));
        $this->priceBids = $this->count - $this->garageBids;
        $this->countWord = $gallery ? ($this->count ? 'интерес '.$this->count : '')
            : implode(', ', array_filter([$this->priceBids ? $this->priceBids.' подтв.' : null, $this->garageBids ? $this->garageBids.' в гараж' : null]));
        $this->slot = $offer->isScheduled() ? 'выйдет '.Slots::phrase($offer->slot_at) : null;
        $this->missing = $checkable && $this->draft && ! $this->slot ? array_map(fn ($m) => 'нет '.match ($m) {
            'фотографии' => 'фото', 'марка' => 'марки', 'цена продажи' => 'цены', default => $m,
        }, ChangeOfferState::missing($offer)) : [];
        $this->todo = $user && OfferController::todo($offer, $user);
        // Логотип — из одной выборки вендоров на страницу, а не связью на каждую строку.
        $this->vendor = $offer->vendor_id ? Vendor::badges()->get($offer->vendor_id) : null;
        $this->noVin = ! $busy && blank($offer->vin) && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true);
    }

    /**
     * Списки грузят связи сами; дозагрузка — только если чего-то нет (сам `loadMissing` на строку стоил 4 % страницы).
     * busy — черновик из письма догружается (`AutoScan::states`): «Оценить» и «Нет VIN» — после.
     */
    public static function for(Offer $offer, ?User $user, bool $gallery = false, bool $checkable = false, bool $busy = false): self
    {
        if (! $offer->relationLoaded('parkVehicle') || ! $offer->relationLoaded('deal') || ! $offer->relationLoaded('garageCar')) {
            $offer->loadMissing(['parkVehicle:id,car_id,state,accepted_at,created_at', 'deal', 'garageCar']);
        }

        return new self($offer, $user, $gallery, $checkable, $busy);
    }

    public function correcting(): bool
    {
        return $this->corrections->isNotEmpty();
    }

    public function proposes(): bool
    {
        return $this->corrections->contains->proposes();
    }

    /** Корректировку попросили, а менеджер ещё не ответил. */
    public function waits(): bool
    {
        return $this->corrections->contains(fn (Estimate $e) => ! $e->isGiven());
    }

    /** Менеджер оценил неоценённый черновик (11.10.2026): у цены — закупочная → его оценка. */
    public function estimated(): bool
    {
        return $this->rate && $this->offer->relationLoaded('openEstimates') && $this->offer->openEstimates->contains->isGiven();
    }
}
