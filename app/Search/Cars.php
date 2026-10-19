<?php

namespace App\Search;

use App\Garage\Car as GarageCar;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Offers\Binding;
use App\Offers\Deal;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Support\HtmlString;

/**
 * Группа «ТС» выдачи: машина целиком — попадания из предложений, ТС парковки, сделок и гаража — одна строка на запись
 * машины (`Cars\Car`); номер из таблицы Кати и цепочка «Из писем» встают к ней по номеру убытка или VIN. Где машина —
 * фразой кусками: «в сделке у Иванова», «Краснопутиловская, с 8 окт», «в таблице Кати, не заведена». Точное совпадение
 * номера или VIN — первым, живые раньше закрытых.
 */
final class Cars
{
    /** @var array<string, Hit> */
    private array $hits = [];

    /** @var array<string, string> номер убытка и VIN известной машины → её строка */
    private array $alias = [];

    /** @var array<int, true> предложения, что уже стоят строкой своей сделки или гаража (сайт) */
    private array $own = [];

    public function __construct(private Term $t) {}

    public function offer(Offer $o, string $link, bool $crm): void
    {
        if (isset($this->own[$o->id])) {
            return;
        }
        $key = $this->car($o->car_id, $crm ? $o->claim_ref_key : null, $o->vin);
        // Чья сделка и чей гараж — админу CRM; модератору (черновики) люди сделок ни к чему.
        $who = $crm && auth()->user()?->canManageCrm();
        $state = match ($o->state) {
            // На сайте черновик видят только в галерее и на оценке.
            OfferState::Draft => $crm ? $o->crmTab() : ($o->isGallery() ? 'в галерее' : 'нужно оценить'),
            OfferState::Sold => 'в сделке'.(($name = $o->deal?->buyer?->shortName()) && $who ? ' у '.$name : ''),
            OfferState::Garage => 'в гараже'.(($name = $o->garageCar?->manager?->shortName()) && $who ? ' у '.$name : ''),
            // Где он в CRM — названием вкладки (13.10.2026), на сайте — как видит человек.
            default => $crm ? $o->crmTab() : mb_strtolower($o->state->labelFor(auth()->user())),
        };
        $hit = $this->hit($key, fn () => new Hit($o->titleWithYear(), $link, ref: $crm ? $o->claim_ref : null, vendorId: $crm ? $o->vendor_id : null));
        // Предложение главнее ТС парковки: строка ведёт в редактор, а место парковки остаётся фразой.
        $hit->href = $link;
        $hit->external = false;
        $hit->title = $o->titleWithYear();
        array_unshift($hit->line, $state);
        // Срок обязывающего — админу CRM, пока он что-то значит (19.10.2026): горит — красным.
        if ($who && Binding::matters($o)) {
            $word = Binding::word($o);
            array_splice($hit->line, 1, 0, [new HtmlString('<span class="'.($word ? 'text-danger' : '').'">обязывающее до '.e(Binding::date($o->binding_until)).($word ? ', '.e($word) : '').'</span>')]);
        }
        // На сайте номер убытка не ищется — и выше от него не встаёт (по рангу его можно было бы угадать).
        $hit->rank = min($hit->rank, $this->exact($crm ? $o->claim_ref_key : null, $o->vin, (string) $o->number) ? 0 : (in_array($o->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true) ? 2 : 1));
    }

    public function vehicle(Vehicle $v, string $link, bool $external = false): void
    {
        $key = $this->car($v->car_id, $v->ref_key, $v->vin);
        $where = match ($v->state) {
            VehicleState::Stored => trim(($v->yard?->name ?? 'на парковке').($v->accepted_at ? ', с '.$v->accepted_at->translatedFormat('j M') : '')),
            VehicleState::Expected => 'ждём на парковке',
            VehicleState::Released => 'выдана с парковки'.($v->released_at ? ' '.$v->released_at->translatedFormat('j M') : ''),
            VehicleState::Cancelled => 'не привезена',
        };
        $hit = $this->hit($key, fn () => new Hit($v->titleWithYear(), $link, ref: $v->ref, vendorId: $v->vendor_id, external: $external));
        $hit->line[] = $where;
        $hit->rank = min($hit->rank, $this->exact($v->ref_key, $v->vin) ? 0 : ($v->state->isFinal() ? 2 : 1));
    }

    /**
     * Номер из таблицы Кати. Живого предложения нет (не заводили или оно в архиве) — «Завести» рядом со строкой: из архива
     * оно поднимается в черновик. `$offer` — предложение этого номера, найденное одним запросом на всю выдачу.
     */
    public function legacy(LegacyClaim $c, ?Offer $offer): void
    {
        $live = $offer && ! in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true);
        $hit = $this->hit($this->key($c->claim_ref_key, null, 'l'.$c->id), fn () => new Hit($c->claim_ref, '/?preset=legacy', ref: $c->claim_ref));
        $hit->line[] = $live ? 'в таблице Кати' : 'в таблице Кати, не заведена';
        if (! $live) {
            $hit->action = ['url' => "/offers/legacy/{$c->id}/create", 'label' => 'Завести'];
        }
        $hit->rank = min($hit->rank, $this->exact($c->claim_ref_key) ? 0 : 1);
    }

    public function candidate(Candidate $c, string $link): void
    {
        $key = $this->key(Code::key($c->code), $c->value('vin'), 'c'.$c->id);
        $hit = $this->hit($key, fn () => new Hit($c->title(), $link, ref: $c->code, vendorId: $c->vendor_id));
        $hit->line[] = 'в письмах, не заведена';
        $hit->rank = min($hit->rank, 1);
    }

    public function siteDeal(Deal $d): void
    {
        $this->own[$d->offer_id] = true;
        $hit = $this->hit($this->car($d->offer->car_id), fn () => new Hit($d->offer->titleWithYear(), $d->href()));
        $hit->href = $d->href();
        $hit->line[] = 'ваша сделка';
        $hit->rank = 0;
    }

    public function siteGarage(GarageCar $c): void
    {
        $this->own[$c->offer_id] = true;
        $hit = $this->hit($this->car($c->offer->car_id), fn () => new Hit($c->offer->titleWithYear(), $c->url()));
        $hit->href = $c->url();
        $hit->line[] = 'в гараже, '.mb_strtolower($c->state->label());
        $hit->rank = 0;
    }

    /** @return list<Hit> */
    public function top(int $n): array
    {
        $hits = array_values($this->hits);
        // Сортировка устойчивая: внутри ранга — порядок появления, каждая группа уже отсортирована свежестью.
        usort($hits, fn (Hit $a, Hit $b) => $a->rank <=> $b->rank);

        return array_map(function (Hit $h) {
            $h->line = array_values(array_unique(array_filter($h->line)));

            return $h;
        }, array_slice($hits, 0, $n));
    }

    private function hit(string $key, \Closure $make): Hit
    {
        return $this->hits[$key] ??= $make();
    }

    /** Строка машины; её номер убытка и VIN запоминаются — номер из таблицы Кати и цепочка писем встанут к ней. */
    private function car(int $carId, ?string $claim = null, ?string $vin = null): string
    {
        $key = 'car:'.$carId;
        $claim && $this->alias['claim:'.$claim] = $key;
        $vin && strlen($vin) === 17 && $this->alias['vin:'.$vin] = $key;

        return $key;
    }

    /** Ключ строки внешнего (таблица Кати, письма): известная машина по номеру или VIN, иначе своя строка. */
    private function key(?string $claim, ?string $vin, string $own): string
    {
        return ($claim ? $this->alias['claim:'.$claim] ?? null : null)
            ?? ($vin && strlen($vin) === 17 ? $this->alias['vin:'.$vin] ?? null : null)
            ?? ($claim ? 'claim:'.$claim : $own);
    }

    private function exact(?string $claimKey, ?string $vin = null, ?string $number = null): bool
    {
        return ($this->t->key && $claimKey === $this->t->key)
            || ($this->t->vin && $vin === $this->t->vin)
            || ($this->t->digits && $number === $this->t->digits);
    }
}
