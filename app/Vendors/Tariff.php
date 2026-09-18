<?php

namespace App\Vendors;

use App\Cars\Category;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Строка прайса. Без вендора — базовый прайс ПРАЙМ, с вендором — его
 * договорной; без площадки — на все, без категории — на любую. Хранение и
 * негабарит идут ступенями по суткам (`from_day`): бесплатные дни — ступень с
 * нулевой ценой. Цена живёт с `valid_from` по `valid_to`: новая цена — новая
 * строка, старые счета не пересчитываются.
 */
#[Fillable(['vendor_id', 'yard_id', 'category', 'service', 'from_day', 'km_included', 'price', 'vat', 'valid_from', 'valid_to', 'note'])]
class Tariff extends Model
{
    protected $table = 'park_tariffs';

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'service' => TariffService::class,
            'price' => 'float',
            'vat' => 'bool',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class);
    }

    /** Действующие на дату строки услуги, подходящие машине по вендору, площадке и категории. */
    public function scopeActiveOn(Builder $q, ?CarbonInterface $on = null): Builder
    {
        $day = ($on ?? now())->toDateString();

        return $q->whereDate('valid_from', '<=', $day)->where(fn ($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }

    /**
     * Лестница ступеней самого конкретного уровня: вендор важнее площадки,
     * площадка важнее категории. Уровень берётся целиком — ступени вендора и
     * базовые не смешиваются, иначе бесплатные дни одного прибавились бы к
     * ценам другого.
     *
     * @return Collection<int, self> отсортированы по from_day
     */
    public static function ladder(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service, ?CarbonInterface $on = null): Collection
    {
        $rows = self::query()->where('service', $service)->activeOn($on)
            ->where(fn ($q) => $q->whereNull('vendor_id')->when($vendorId, fn ($q) => $q->orWhere('vendor_id', $vendorId)))
            ->where(fn ($q) => $q->whereNull('yard_id')->when($yardId, fn ($q) => $q->orWhere('yard_id', $yardId)))
            ->where(fn ($q) => $q->whereNull('category')->when($category, fn ($q) => $q->orWhere('category', $category)))
            ->get();
        if ($rows->isEmpty()) {
            return $rows;
        }
        $level = fn (self $t) => ($t->vendor_id ? 4 : 0) + ($t->yard_id ? 2 : 0) + ($t->category ? 1 : 0);
        $top = $rows->max($level);

        return $rows->filter(fn (self $t) => $level($t) === $top)->sortBy('from_day')->values();
    }

    /** @return Collection<int, self> */
    public static function ladderFor(Vehicle $vehicle, TariffService $service, ?CarbonInterface $on = null): Collection
    {
        return self::ladder($vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, $service, $on);
    }

    /** Одна строка услуги без ступеней (эвакуация, осмотр…) — первая ступень лестницы. */
    public static function resolve(Vehicle $vehicle, TariffService $service, ?CarbonInterface $on = null): ?self
    {
        return self::ladderFor($vehicle, $service, $on)->first();
    }

    /** Ставка на N-е сутки по лестнице: последняя ступень, чей from_day не больше N. */
    public static function rateOnDay(Collection $ladder, int $day): ?float
    {
        $rate = null;
        foreach ($ladder as $tier) {
            if ($tier->from_day <= $day) {
                $rate = $tier->price;
            }
        }

        return $rate;
    }

    /** «300 ₽/сут с 4 дн», «5 000 ₽ до 30 км», «150 ₽/км». */
    public static function ladderLabel(Collection $ladder): ?string
    {
        if ($ladder->isEmpty()) {
            return null;
        }
        $service = $ladder->first()->service;
        if ($service->tiered()) {
            $paid = $ladder->first(fn (self $t) => $t->price > 0);
            if (! $paid) {
                return 'бесплатно';
            }

            return Money::rub($paid->price).'/сут'.($paid->from_day > 1 ? ' с '.$paid->from_day.' дн' : '');
        }
        $t = $ladder->first();

        return match ($service) {
            TariffService::Tow => Money::rub($t->price).($t->km_included ? ' до '.$t->km_included.' км' : ''),
            TariffService::TowKm => Money::rub($t->price).'/км',
            TariffService::Idle => Money::rub($t->price).'/ч',
            default => Money::rub($t->price),
        };
    }

    public function label(): string
    {
        return self::ladderLabel(collect([$this])) ?? '';
    }
}
