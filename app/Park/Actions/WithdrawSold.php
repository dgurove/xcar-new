<?php

namespace App\Park\Actions;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\City;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Продана не через нас — из CRM (13.11.2026, владелец: Альфа присылает заявку на выдачу, а машина уже заведена «Сразу в
 * продажу» и висит в «Без продажной цены»): живая продажа ТС (черновик, галерея, слот, аукцион, опубликованное) уходит в
 * архив сама — сразу после заявки на выдачу (`CreateRequest`) и подбором в часах парковки (`sweep`). Ждущие подтверждения
 * отклоняются (`ChangeOfferState`), причина — заметкой в «Истории». Не наша ли выдача — `Sale::soldElsewhere`: сделку и
 * гараж не трогаем никогда. Архив, а не удаление: цены, заметки и письма остаются, вернуть — «Вернуть в черновики»; сама в
 * продажу ТС больше не встанет (`AutoSale` берёт только ТС без продажи и без заявки на выдачу).
 */
final class WithdrawSold
{
    private const LIVE = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    public function __construct(private ChangeOfferState $change) {}

    public function __invoke(Vehicle $vehicle): ?Offer
    {
        $offer = $vehicle->car_id ? Offer::withoutGlobalScopes()->where('car_id', $vehicle->car_id)->whereIn('state', self::LIVE)->latest('id')->first() : null;
        if (! $offer || ! Sale::soldElsewhere($vehicle)) {
            return null;
        }
        $request = Request::withoutGlobalScope(City::SCOPE)->where('vehicle_id', $vehicle->id)->where('type', RequestType::Release)->latest('id')->first();
        try {
            ($this->change)($offer, OfferState::Archived, null);
        } catch (Throwable $e) {
            Log::warning('park: проданную не через нас не убрали из CRM', ['offer' => $offer->number, 'error' => $e->getMessage()]);

            return null;
        }
        $offer->log(OfferEventType::Note, null, ['text' => 'Продана не через нас: '.($request ? 'заявка на выдачу на парковке № '.$request->id : 'ТС выдана с парковки').' — в архив']);
        Nav::forgetStaffCounts();

        return $offer;
    }

    /** Часы парковки: живые продажи ТС, проданных не через нас (выдача мимо заявки, старые случаи). Сколько убрано. */
    public function sweep(): int
    {
        return $this->candidates()->get()->filter(fn (Vehicle $v) => $this($v))->count();
    }

    /** ТС с заявкой на выдачу или выданные, у машины которых есть живая продажа. */
    public function candidates()
    {
        return Vehicle::withoutGlobalScope(City::SCOPE)
            ->where(fn ($q) => $q->where('state', VehicleState::Released)
                ->orWhereHas('requests', fn ($r) => $r->withoutGlobalScope(City::SCOPE)->where('type', RequestType::Release)))
            ->whereHas('car.offer', fn ($o) => $o->withoutGlobalScopes()->whereIn('offers.state', array_map(fn ($s) => $s->value, self::LIVE)));
    }
}
