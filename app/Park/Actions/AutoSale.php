<?php

namespace App\Park\Actions;

use App\Cars\Identity;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Scan\AutoScan;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
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
 * прочитаны (`AutoScan::finished`), фото доехали (`ImportThreadFiles`) и оценочная известна. Закупочную черновику
 * ставит правило от оценочной (`Sale::toOffer`), продажной нет — он встаёт в «Без продажной цены» со значком связи.
 * Не сработало при письме (выкладка убила задачу, оценочная прочлась вторым проходом) — подбирают часы парковки (`sweep`).
 */
final class AutoSale
{
    /** Заявки из писем не старше стольких дней: давние разбирает админ. */
    private const DAYS = 7;

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
        // Эта машина уже есть в CRM. Живое предложение — связать, как «В продажу», но без плашки: его заводили не мы.
        // Архивное, отменённое, выданное и уже связанное с другой ТС — не трогать: из архива сама машина не возвращается.
        $holder = Identity::offerFor($vehicle->ref_key, $vehicle->vin);
        if ($holder && (in_array($holder->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)
            || Vehicle::withoutGlobalScope(City::SCOPE)->where('offer_id', $holder->id)->exists())) {
            return null;
        }
        try {
            $offer = ($this->sell)($vehicle, null);
        } catch (Throwable $e) {
            Log::warning('auto-sale: ТС не ушла в продажу сама', ['vehicle' => $vehicle->id, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $holder) {
            // Решение за админом: плашка «Принять / Отклонить» в окошке и редакторе.
            $offer->forceFill(['proposed_at' => now()])->save();
            $offer->log(OfferEventType::Proposal);
            AutoScan::arrived($offer);
        }
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
        return $this->candidates()->whereKey($vehicle->id)->exists()
            && ! ImportThreadFiles::progressOf($vehicle)
            && ! AutoScan::busy($vehicle);
    }

    private function candidates()
    {
        return Vehicle::withoutGlobalScope(City::SCOPE)
            ->whereNull('offer_id')->whereNull('sold_at')->where('value', '>', 0)->whereNotNull('brand_id')
            // Только пока в «Заявках на приём»: принятую раньше чтения админ выставляет кнопкой, как прежде.
            ->where('state', VehicleState::Expected)
            ->whereHas('vendor', fn ($q) => $q->where('auto_sale', true))
            // Отклонили или сняли с продажи — больше сама не выставляется.
            ->whereDoesntHave('events', fn ($q) => $q->where('type', EventType::Unlinked))
            ->whereHas('requests', fn ($q) => $q->withoutGlobalScope(City::SCOPE)->where('type', RequestType::Intake)
                ->whereNotNull('letter_id')->where('created_at', '>', now()->subDays(self::DAYS)));
    }
}
