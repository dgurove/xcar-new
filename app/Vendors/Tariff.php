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
use Illuminate\Support\Once;

/**
 * Строка прайса. Без вендора — базовый прайс ПРАЙМ, с вендором — его
 * договорной; без площадки — на все, без категории — на любую. Хранение и
 * негабарит идут ступенями по суткам (`from_day`): бесплатные дни — ступень с
 * нулевой ценой, и ступенями по заявленной стоимости (`from_value`: пусто —
 * на любую, иначе от этой суммы и выше — так устроен прайс АльфаСтрахования).
 * Цена живёт с `valid_from` по `valid_to`: новая цена — новая строка, старые
 * счета не пересчитываются.
 */
#[Fillable(['vendor_id', 'yard_id', 'category', 'service', 'from_day', 'from_value', 'km_included', 'price', 'vat', 'valid_from', 'valid_to', 'note'])]
class Tariff extends Model
{
    protected $table = 'park_tariffs';

    /** Ячейки прайса (без отбора по дате) и готовые лестницы на время запроса: ставку спрашивают на каждый
     * день по каждой ТС, а ответ зависит только от ключа. */
    private static array $cells = [];

    private static array $memo = [];

    private static array $steps = [];

    /** Та самая память `once()`, на которой кэши построены: Octane меняет её между запросами (`FlushOnce`). */
    private static ?Once $seen = null;

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'service' => TariffService::class,
            'from_value' => 'integer',
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
     * ценам другого. Внутри уровня — одна ступень по стоимости.
     *
     * @return Collection<int, self> отсортированы по from_day
     */
    public static function ladder(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service, ?CarbonInterface $on = null, ?int $value = null): Collection
    {
        return self::ladderOn($vendorId, $yardId, $category, $service, ($on ?? now())->toDateString(), $value);
    }

    /**
     * То же, но день строкой «2026-09-23»: хранение спрашивает ставку на каждый день по каждой ТС, и Carbon
     * на каждый день обходится дороже самого отбора.
     *
     * @return Collection<int, self>
     */
    public static function ladderOn(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service, string $day, ?int $value = null): Collection
    {
        $cell = self::cell($vendorId, $yardId, $category, $service);

        return self::$memo[self::key($cell, $day, $value)] ??= self::pick($cell['rows'], $day, $value);
    }

    /**
     * Лестница целиком, со всеми ступенями по стоимости, — для сводки условий вендора («250…450 ₽/сут по
     * стоимости»): тот же уровень, что у начисления, но без выбора ступени под стоимость конкретной ТС.
     *
     * @return Collection<int, self>
     */
    public static function ladderWhole(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service): Collection
    {
        $day = now()->toDateString();
        $rows = self::cell($vendorId, $yardId, $category, $service)['rows'];
        $live = array_values(array_filter($rows, fn (self $t) => $t->valid_from->toDateString() <= $day && (! $t->valid_to || $t->valid_to->toDateString() >= $day)));
        if (! $live) {
            return collect();
        }
        $level = fn (self $t) => ($t->vendor_id ? 4 : 0) + ($t->yard_id ? 2 : 0) + ($t->category ? 1 : 0);
        $top = max(array_map($level, $live));

        return collect($live)->filter(fn (self $t) => $level($t) === $top)->sortBy(fn (self $t) => [$t->from_day, $t->from_value ?? -1])->values();
    }

    /**
     * Ступени лестницы простыми числами `[[from_day, price], …]` — для расчёта хранения: ставку спрашивают на
     * каждый день по каждой ТС, и чтение полей модели в таком цикле обходится дороже самого отбора.
     *
     * @return list<array{0: int, 1: float}>
     */
    public static function stepsOn(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service, string $day, ?int $value = null): array
    {
        $cell = self::cell($vendorId, $yardId, $category, $service);
        $key = self::key($cell, $day, $value);
        if (isset(self::$steps[$key])) {
            return self::$steps[$key];
        }
        $ladder = self::$memo[$key] ??= self::pick($cell['rows'], $day, $value);

        return self::$steps[$key] = $ladder->map(fn (self $t) => [(int) $t->from_day, (float) $t->price])->all();
    }

    /**
     * Дни между двумя правками прайса дают одну и ту же лестницу, поэтому в ключ идёт не сам день, а
     * промежуток между датами, в которые набор строк меняется.
     *
     * @param  array{key: string, rows: list<self>, marks: list<string>}  $cell
     */
    private static function key(array $cell, string $day, ?int $value): string
    {
        $span = 0;
        foreach ($cell['marks'] as $mark) {
            if ($day < $mark) {
                break;
            }
            $span++;
        }

        return $cell['key'].'|'.$value.'|'.$span;
    }

    /**
     * Строки, которые вообще могут подойти этой ячейке (без отбора по дате), и дни, в которые набор меняется.
     *
     * @return array{key: string, rows: list<self>, marks: list<string>}
     */
    private static function cell(?int $vendorId, ?int $yardId, ?Category $category, TariffService $service): array
    {
        self::warm();
        $key = $vendorId.'|'.$yardId.'|'.$category?->value.'|'.$service->value;
        if (isset(self::$cells[$key])) {
            return self::$cells[$key];
        }
        $rows = self::rows($service)->filter(fn (self $t) => ($t->vendor_id === null || $t->vendor_id === $vendorId)
            && ($t->yard_id === null || $t->yard_id === $yardId)
            && ($t->category === null || $t->category === $category))->values()->all();
        $marks = [];
        foreach ($rows as $t) {
            $marks[$t->valid_from->toDateString()] = true;
            if ($t->valid_to) {
                $marks[$t->valid_to->copy()->addDay()->toDateString()] = true;
            }
        }
        $marks = array_keys($marks);
        sort($marks);

        return self::$cells[$key] = ['key' => $key, 'rows' => $rows, 'marks' => $marks];
    }

    /**
     * @param  list<self>  $rows
     * @return Collection<int, self>
     */
    private static function pick(array $rows, string $day, ?int $value): Collection
    {
        $live = array_values(array_filter($rows, fn (self $t) => $t->valid_from->toDateString() <= $day
            && (! $t->valid_to || $t->valid_to->toDateString() >= $day)));
        if (! $live) {
            return collect();
        }
        $level = fn (self $t) => ($t->vendor_id ? 4 : 0) + ($t->yard_id ? 2 : 0) + ($t->category ? 1 : 0);
        $top = max(array_map($level, $live));
        $live = array_values(array_filter($live, fn (self $t) => $level($t) === $top));
        $live = self::step($live, $value);
        // При равных сутках ступень по стоимости идёт после строки «на любую» — она и победит в `rateOnDay`.
        usort($live, fn (self $a, self $b) => [$a->from_day, $a->from_value ?? -1] <=> [$b->from_day, $b->from_value ?? -1]);

        return collect($live);
    }

    /**
     * Ступень по стоимости: самая высокая, чья сумма не больше заявленной стоимости ТС. Стоимость неизвестна
     * или ниже первой ступени — остаются строки на любую стоимость; их нет — ставки нет (решение владельца:
     * не считать и показать тег «Нет стоимости», а не брать минимальную ступень).
     *
     * @param  list<self>  $rows
     * @return list<self>
     */
    private static function step(array $rows, ?int $value): array
    {
        $steps = array_filter($rows, fn (self $t) => $t->from_value !== null);
        if (! $steps) {
            return $rows;
        }
        $at = null;
        if ($value !== null) {
            foreach ($steps as $t) {
                if ($t->from_value <= $value && ($at === null || $t->from_value > $at)) {
                    $at = $t->from_value;
                }
            }
        }

        // Строки без стоимости остаются рядом со ступенью: у вендора это бесплатные первые дни или общая
        // ставка, и они живут ступенями по суткам, а не вместо ступени по стоимости.
        return array_values(array_filter($rows, fn (self $t) => $t->from_value === $at || $t->from_value === null));
    }

    /**
     * Все строки услуги одним запросом на время запроса: хранение на лету спрашивает лестницу на каждый день
     * по каждой ТС, прайс же — десятки строк. Правка прайса сбрасывает память (`booted`), Octane — между запросами.
     *
     * @return Collection<int, self>
     */
    private static function rows(TariffService $service): Collection
    {
        return once(fn () => self::query()->where('service', $service)->get());
    }

    /**
     * Кэши живут ровно столько, сколько строки прайса под `once()`: сверяем саму память запроса, а не её
     * номер (номер объекта переиспользуется). Иначе воркер Octane отдавал бы цены, заведённые до правки,
     * пока не перезапустится, — а прайс правят в CRM, а считают на стоянке, это разные воркеры.
     */
    private static function warm(): void
    {
        $once = Once::instance();
        if (self::$seen !== $once) {
            self::$seen = $once;
            self::forget();
        }
    }

    /** Забыть посчитанное: правка строки прайса и смена памяти запроса. */
    public static function forget(): void
    {
        self::$cells = [];
        self::$memo = [];
        self::$steps = [];
    }

    protected static function booted(): void
    {
        $flush = function () {
            self::forget();
            Once::flush();
        };
        static::saved(function (self $t) use ($flush) {
            $flush();
            Vendor::markOnPark($t->vendor_id);
        });
        static::deleted($flush);
    }

    /** @return Collection<int, self> */
    public static function ladderFor(Vehicle $vehicle, TariffService $service, ?CarbonInterface $on = null): Collection
    {
        return self::ladder($vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, $service, $on, $vehicle->value);
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

    /** «300 ₽/сут с 4 дн», «250…450 ₽/сут по стоимости», «5 000 ₽ до 30 км», «150 ₽/км». */
    public static function ladderLabel(Collection $ladder): ?string
    {
        if ($ladder->isEmpty()) {
            return null;
        }
        $service = $ladder->first()->service;
        if ($service->tiered()) {
            $steps = $ladder->filter(fn (self $t) => $t->from_value !== null);
            if ($steps->isNotEmpty()) {
                // Вся лестница по стоимости одним чипом: ступени видно в шторке, в строке важен разброс.
                // Одна ступень — пишем её порог: иначе чип обещал бы цену и тем ТС, что дешевле порога.
                $prices = $steps->where('price', '>', 0)->pluck('price');
                if ($prices->isEmpty()) {
                    return 'бесплатно';
                }
                if ($steps->pluck('from_value')->unique()->count() > 1) {
                    return Money::nums($prices->min()).'…'.Money::rub($prices->max()).'/сут по стоимости';
                }
                $one = $steps->first(fn (self $t) => $t->price > 0);

                // «от 0 ₽» не пишем: это самая нижняя ступень, порог там ничего не говорит.
                return Money::rub($one->price).'/сут'.($one->from_day > 1 ? ' с '.$one->from_day.' дн' : '').($one->from_value > 0 ? ' от '.Money::rub($one->from_value) : '');
            }
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
