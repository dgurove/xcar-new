<?php

namespace App\Offers\Intake;

use App\Cars\CarText;
use App\Cars\Identity;
use App\Cars\IdentityTaken;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Черновик предложения — одна дверь (26.10.2026, приборка: у черновика было девять дверей, у каждой свой поиск дубля,
 * вендор, «архивное — снова черновик»; маршрут, письма и Мигторг вставали только у почты). Письмо, парковка, закупка,
 * таблица Кати, Мигторг из оценки и «+ Новый» — адаптеры: собирают `DraftSource` и делают своё до и после.
 *
 * По порядку:
 * 1. Замок на машину (`DraftSource::lockKey`) — двойное нажатие и «Завести» руками рядом с почтой не дают двух черновиков.
 * 2. Предложение этой машины уже есть (`Identity::offerFor`, у парковки — машина ТС): архивное — обратно в черновики,
 *    отменённое — тоже, если источник это разрешает; остальное возвращается как есть (`existing`) — что с ним делать,
 *    решает дверь (парковка: «уже продана»).
 * 3. Нового нет — `CreateOffer` пустым («Заведён …» в истории) и всё одним `UpdateOffer` (`why` источника): оттуда одинаково
 *    для всех встают НДС вендора, маршрут, вывоз, письма той же машины и лот Мигторга. Вендор — из полей, иначе по виду
 *    номера (`CarText::vendorOf`); цены из суммы — `Pricing::fromAmount`.
 */
final class OpenDraft
{
    public const CREATED = 'created';

    public const REOPENED = 'reopened';

    public const EXISTING = 'existing';

    public function __construct(private CreateOffer $create, private UpdateOffer $update, private ChangeOfferState $change) {}

    /** @return array{0: Offer, 1: string} предложение и что с ним случилось: `created`, `reopened`, `existing` */
    public function __invoke(DraftSource $source, ?User $by): array
    {
        $key = $source->lockKey();

        return $key ? Cache::lock("draft:{$key}", 30)->block(15, fn () => $this->open($source, $by)) : $this->open($source, $by);
    }

    /** @return array{0: Offer, 1: string} */
    private function open(DraftSource $source, ?User $by): array
    {
        $existing = $source->car ? $source->car->offer()->first() : Identity::offerFor($source->claimKey(), $source->vin ?? $source->fields['vin'] ?? null);
        if ($existing) {
            return $this->reopen($existing, $source, $by);
        }
        try {
            $offer = DB::transaction(function () use ($source, $by) {
                $offer = ($this->create)($by, [], $source->created, car: $source->car);

                return ($this->update)($offer, $this->fields($source), $by, $source->why ? ['why' => $source->why] : []);
            });
        } catch (IdentityTaken $e) {
            // Номер или VIN только что занял другой черновик (или VIN — у предложения с другим номером): это оно.
            if (! $e->holder instanceof Offer) {
                throw $e;
            }

            return $this->reopen($e->holder, $source, $by);
        }

        return [$offer, self::CREATED];
    }

    /** @return array{0: Offer, 1: string} */
    private function reopen(Offer $offer, DraftSource $source, ?User $by): array
    {
        if ($offer->state === OfferState::Cancelled && $source->reopenCancelled) {
            $offer = ($this->change)($offer, OfferState::Archived, $by);
        }
        if ($offer->state !== OfferState::Archived) {
            return [$offer, self::EXISTING];
        }

        return [($this->change)($offer, OfferState::Draft, $by), self::REOPENED];
    }

    /** Поля источника, вендор по виду номера, цены из суммы. */
    private function fields(DraftSource $source): array
    {
        $fields = $source->fields;
        if ($source->claimRef !== null) {
            $fields['claim_ref'] ??= $source->claimRef;
        }
        if ($source->vin !== null) {
            $fields['vin'] ??= $source->vin;
        }
        if (! isset($fields['vendor_id']) && ! empty($fields['claim_ref'])) {
            $fields['vendor_id'] = CarText::vendorOf($fields['claim_ref']);
        }
        // Вендор парковки — его сторона продаж (`Vendor::forCrm`) сразу: от неё НДС, маршрут и строка в истории.
        $fields['vendor_id'] = Vendor::forCrm($fields['vendor_id'] ?? null);
        if ($source->amount && ! isset($fields['floor_price'])) {
            $fields += Pricing::fromAmount($fields['vendor_id'] ?? null, $source->amount);
        }

        return array_filter($fields, fn ($v) => $v !== null && $v !== '');
    }
}
