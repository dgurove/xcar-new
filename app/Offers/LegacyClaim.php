<?php

namespace App\Offers;

use App\Cars\Car;
use App\Mail\Extraction\Code;
use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Номер убытка из таблицы Кати (10.10.2026): сделка идёт вне системы, в ней надо разобраться. Флага у предложения нет —
 * членство считается по `claim_ref_key`: черновик с номером из списка стоит во вкладке «Из таблицы Кати», а не в
 * рабочих, и любая дверь, которая заводит предложение или пишет ему номер, кладёт его туда сама.
 */
#[Fillable(['claim_ref', 'position', 'added_by'])]
class LegacyClaim extends Model
{
    use FastAttributes;

    public function setClaimRefAttribute(?string $value): void
    {
        $this->attributes['claim_ref'] = Code::normalize($value);
        $this->attributes['claim_ref_key'] = Code::key($value);
    }

    /** Предложения с номером из списка. */
    public static function onList(Builder $q): Builder
    {
        return $q->whereIn('offers.car_id', Car::query()->whereIn('claim_ref_key', self::query()->select('claim_ref_key'))->select('id'));
    }

    /** Предложения без номера из списка — рабочие вкладки черновиков. */
    public static function offList(Builder $q): Builder
    {
        return $q->whereNotIn('offers.car_id', Car::query()->whereIn('claim_ref_key', self::query()->select('claim_ref_key'))->select('id'));
    }

    /** Номера, которые ещё не заведены: живого предложения нет (архивное «Завести» поднимет в черновик). */
    public static function ghosts(): Builder
    {
        return self::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('offers')->join('cars', 'cars.id', '=', 'offers.car_id')
            ->whereColumn('cars.claim_ref_key', 'legacy_claims.claim_ref_key')->where('offers.is_demo', false)
            ->whereNotIn('offers.state', [OfferState::Archived->value, OfferState::Cancelled->value]))
            ->orderBy('position');
    }

    /**
     * Есть ли номер в таблице Кати — для строк списков (`Offer::crmTab`): весь список ключей одним запросом на веб-запрос,
     * а не запрос на строку (180 на «Без закупочной», аудит 20.10.2026). В очереди и консоли — прямой запрос: `once` там
     * живёт всю задачу.
     */
    public static function has(?string $key): bool
    {
        if (! $key) {
            return false;
        }
        if (app()->runningInConsole()) {
            return self::where('claim_ref_key', $key)->exists();
        }

        return isset(once(fn () => self::query()->pluck('claim_ref_key')->flip()->all())[$key]);
    }

    public static function listed(?Offer $offer): ?self
    {
        return $offer?->claim_ref_key ? self::where('claim_ref_key', $offer->claim_ref_key)->first() : null;
    }

    /** Предложение по номеру (без демо): живое первым. */
    public function offer(): ?Offer
    {
        return Offer::whereCar('claim_ref_key', $this->claim_ref_key)->where('is_demo', false)->first();
    }
}
