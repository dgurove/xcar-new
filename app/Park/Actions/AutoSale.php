<?php

namespace App\Park\Actions;

use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Scan\AutoScan;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\ParkValue;
use App\Park\City;
use App\Park\EventType;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Сразу в продажу» (13.10.2026, начальник): ТС вендора с `vendors.auto_sale` (АльфаСтрахование Москва и СПб), что
 * почта завела заявкой на приём, уходит в продажу сама, как кнопкой «В продажу» (`PutOnSale`), — когда документы
 * прочитаны (`AutoScan::finished`) и фото доехали (`ImportThreadFiles`). Закупочную черновику ставит правило от оценочной
 * (`PutOnSale`, `Pricing::floorFrom`), продажной нет — он встаёт в «Без продажной цены».
 * Не сработало при письме (выкладка убила задачу, оценочная прочлась вторым проходом) — подбирают часы парковки (`sweep`).
 *
 * С 26.11.2026 (владелец по Exeed 785R/046/01928/26: черновик с Мигторга уже был, заявка его не тронула, и машина с оценочной
 * 2,5 млн лежала в «Без закупочной» среди 500 других — «их никто не заметит, это потерянные деньги»): черновик — и без марки и
 * оценочной (не распознались — «не распознано» в строке), и у принятой раньше чтения; чтение застряло — через `WAIT` всё
 * равно. Черновик уже есть — он помечается (`offers.park_intake_at`) и встаёт в «Без продажной цены», пустая оценочная — с
 * парковки (`ParkValue::adopt`). Каждую ТС — один раз (событие `Proposal` у продажи): правило Мигторга или «Оценочная
 * неактуальна» снимает метку (`DropValuation`), и машина остаётся в «Без закупочной».
 */
final class AutoSale
{
    /** Заявки из писем не старше стольких дней: давние разбирает админ. */
    private const DAYS = 7;

    /** Минут с заявки, после которых черновик встаёт, даже если документы и фото ещё не дочитаны: поля догонят сами. */
    private const WAIT = 30;

    public function __construct(private PutOnSale $sell) {}

    public function __invoke(Vehicle $vehicle): ?Offer
    {
        // Итог чтения документов и конец задачи фото приходят из разных работников разом — продаёт кто-то один.
        return Cache::lock('auto-sale:v:'.$vehicle->id, 60)->get(fn () => $this->sell($vehicle->fresh())) ?: null;
    }

    private function sell(?Vehicle $vehicle): ?Offer
    {
        if (! $vehicle || ! $this->ready($vehicle)) {
            return null;
        }
        // У машины уже есть продажа в CRM (её заводили не мы). Архивную, отменённую и выданную не трогаем: из архива сама
        // машина не возвращается; идущую (галерея, продажа, сделка) — тоже: ей не место в «Без продажной цены».
        $holder = $vehicle->carRecord()->offer;
        if ($holder) {
            // Оценочную сняли как неактуальную (правило Мигторга, «Оценочная неактуальна») — ждём оценку поставщика в «Без закупочной».
            $dropped = $holder->value === null && $holder->value_at !== null;

            return $holder->state === OfferState::Draft && ! $dropped ? $this->mark($holder) : null;
        }
        try {
            $offer = ($this->sell)($vehicle, null);
        } catch (Throwable $e) {
            Log::warning('auto-sale: ТС не ушла в продажу сама', ['vehicle' => $vehicle->id, 'error' => $e->getMessage()]);

            return null;
        }
        // Решение за админом: плашка «Принять / Отклонить» в окошке и редакторе.
        $offer->forceFill(['proposed_at' => now(), 'park_intake_at' => now()])->save();
        $offer->log(OfferEventType::Proposal);
        AutoScan::arrived($offer);
        Nav::forgetStaffCounts();

        return $offer;
    }

    /** Черновик машины уже был (Мигторг, письмо, руками): в «Без продажной цены», пустая оценочная — с парковки. */
    private function mark(Offer $offer): Offer
    {
        ParkValue::adopt($offer);
        $offer->refresh()->forceFill(['park_intake_at' => now()])->save();
        $offer->log(OfferEventType::Proposal, null, ['existing' => true]);
        AutoScan::arrived($offer);
        Nav::forgetStaffCounts();

        return $offer;
    }

    /** Часы парковки (`park:tick`): ТС, что должны были уйти в продажу, но не ушли. Возвращает, сколько ушло. */
    public function sweep(): int
    {
        return $this->candidates()->get()->filter(fn (Vehicle $v) => $this($v))->count();
    }

    private function ready(Vehicle $vehicle): bool
    {
        if (! $this->candidates()->whereKey($vehicle->id)->exists()) {
            return false;
        }
        // Документы и фото ещё едут — ждём, но не дольше `WAIT` с заявки: застрявшее чтение не держит машину вне CRM.
        $late = $vehicle->requests()->withoutGlobalScope(City::SCOPE)->where('type', RequestType::Intake)->whereNotNull('letter_id')
            ->where('created_at', '<', now()->subMinutes(self::WAIT))->exists();

        return $late || (! ImportThreadFiles::progressOf($vehicle) && ! AutoScan::busy($vehicle));
    }

    private function candidates()
    {
        return Vehicle::withoutGlobalScope(City::SCOPE)
            // Продажи нет или она черновик, ещё не предложенный из заявки (`Proposal` — один раз на машину).
            ->where(fn ($v) => $v->whereDoesntHave('car.offer')->orWhereHas('car.offer', fn ($o) => $o->where('offers.state', OfferState::Draft)
                ->whereNull('offers.park_intake_at')->whereDoesntHave('events', fn ($e) => $e->where('type', OfferEventType::Proposal))))
            ->whereNull('sold_at')
            // Ждёт приёма или уже принята (FAW Z691/046/10289/26: приняли раньше, чем вписали марку, — черновика не было вовсе).
            ->whereIn('state', [VehicleState::Expected, VehicleState::Stored])
            ->whereHas('vendor', fn ($q) => $q->where('auto_sale', true))
            // Отклонили или сняли с продажи — больше сама не выставляется.
            ->whereDoesntHave('events', fn ($q) => $q->where('type', EventType::Unlinked))
            // Заявка на выдачу — продана не через нас (13.11.2026, владелец: «нет смысла заводить»; `Sale::soldElsewhere`).
            ->whereDoesntHave('requests', fn ($q) => $q->withoutGlobalScope(City::SCOPE)->where('type', RequestType::Release))
            ->whereHas('requests', fn ($q) => $q->withoutGlobalScope(City::SCOPE)->where('type', RequestType::Intake)
                ->whereNotNull('letter_id')->where('created_at', '>', now()->subDays(self::DAYS)));
    }
}
