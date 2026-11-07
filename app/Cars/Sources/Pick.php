<?php

namespace App\Cars\Sources;

use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\CarModel;
use App\Cars\Rejections;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Выбор по спорному полю (07.11.2026) — одна дверь CRM и парковки. Пишется ровно значение варианта (`car_values.write`
 * — уже в виде колонок, проверено при сборе), поэтому выбор не «уходит» и расхождение после него гаснет; проигравшие
 * варианты отклоняются — пришедший потом новый снова спорный. Выбор сверяется с тем, что человек видел (`$seen` — ключ
 * значения сейчас): поменяли, пока смотрел, — ничего не пишется, ответ — что стоит теперь.
 */
final class Pick
{
    public const STALE = 'stale';

    public const DONE = 'done';

    /** @return array{0: string, 1: string} итог и что показать человеку */
    public function choose(Offer|Vehicle $lane, string $field, string $key, ?string $seen, User $by): array
    {
        $car = $lane->carRecord();
        $variant = Variants::for($car, $lane)[$field] ?? null;
        $now = $variant['current']['key'] ?? null;
        $option = collect($variant['options'] ?? [])->firstWhere('key', $key);
        if (! $variant || $now !== ($seen ?: null) || ! $option) {
            return [self::STALE, $this->stale($lane, $field)];
        }
        $titles = array_column($option['sources'], 'title');
        $this->write($lane, $option['write'], $by, $titles);
        // Выбранное — снова в силе, даже если его отклоняли раньше; проигравшие — отклонены.
        Rejections::forget(Rejections::car($car->id), Variants::REJECT, $field);
        $this->reject($car, $field, array_filter([$now, ...array_column($variant['options'], 'key')], fn ($k) => $k !== null && $k !== $key), $by);
        Variants::recount($car->refresh());

        return [self::DONE, Values::title($field).': '.$option['label']];
    }

    /** «Оставить как есть» — варианты поля отклонены. */
    public function keep(Offer|Vehicle $lane, string $field, ?string $seen, User $by): array
    {
        $car = $lane->carRecord();
        $variant = Variants::for($car, $lane)[$field] ?? null;
        if (! $variant || ($variant['current']['key'] ?? null) !== ($seen ?: null)) {
            return [self::STALE, $this->stale($lane, $field)];
        }
        $this->reject($car, $field, array_column($variant['options'], 'key'), $by);
        Variants::recount($car->refresh());

        return [self::DONE, Values::title($field).': оставили как есть'];
    }

    /**
     * Пустое поле, где источники согласны (один вариант), — вписать (владелец 07.11.2026: «сразу в пустое»). Не вписывается
     * поле, которое последним правил человек (стёр — значит, не надо), и спорное — его выбирают.
     */
    public function fillEmpty(Car $car): void
    {
        foreach (array_filter([$car->offer, $car->vehicle]) as $lane) {
            $rows = DB::table('car_values')->where('car_id', $car->id)->whereIn('field', Variants::fields($lane))->orderBy('id')->get();
            $rejected = Rejections::of(Rejections::car($car->id), Variants::REJECT);
            foreach ($rows->groupBy('field') as $field => $group) {
                $columns = Values::columns($field);
                if (Values::current($lane, $field) || array_filter($columns, fn ($c) => $car->byHuman($c))) {
                    continue;
                }
                // Вписывается только точное согласие источников (VIN с разницей в знак — не «согласны»).
                $group = $group->reject(fn ($r) => Variants::rejected($rejected, $field, $r->key));
                if ($group->isEmpty() || $group->pluck('key')->unique()->count() > 1) {
                    continue;
                }
                // Одна машина, которую записать нельзя (гаражная: «ТС в гараже: сначала „Отдали по ошибке“», 08.11.2026 оборвало
                // сбор на проде), не роняет остальные поля и машины.
                try {
                    $this->write($lane, json_decode($group->first()->write, true), null, $group->pluck('title')->unique()->values()->all(), $group->first());
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Поле из источников не вписалось', ['car' => $car->id, 'field' => $field, 'error' => $e->getMessage()]);

                    continue;
                }
                $lane->refresh();
                $car->refresh();
            }
        }
    }

    private function write(Offer|Vehicle $lane, array $data, ?User $by, array $titles, ?object $row = null): void
    {
        // Новая модель источника — в справочник под маркой варианта (`Values::car`).
        if (! empty($data['model_name']) && ($brand = Brand::find($data['brand_id'] ?? null))) {
            $data['model_id'] = CarModel::resolve($brand, $data['model_name'])->id;
        }
        unset($data['model_name']);
        if ($lane instanceof Vehicle) {
            app(UpdateVehicle::class)($lane, $data, $by, $titles);

            return;
        }
        $log = match (true) {
            $by !== null => ['why' => 'Выбрано: '.implode(', ', $titles)],
            $row?->source === 'migtorg' => ['source' => 'migtorg', 'lot' => (int) $row->source_id],
            $row?->source === 'letter' => ['why' => 'Из письма', 'letter' => (int) $row->source_id],
            default => ['why' => 'Из документа «'.implode('», «', $titles).'»', 'sources' => $titles],
        };
        app(UpdateOffer::class)($lane, $data, $by, $log);
    }

    /** @param  iterable<string>  $keys */
    private function reject(Car $car, string $field, iterable $keys, User $by): void
    {
        foreach ($keys as $key) {
            Rejections::add(Rejections::car($car->id), Variants::REJECT, $field, $key, $by);
        }
    }

    private function stale(Offer|Vehicle $lane, string $field): string
    {
        $now = Values::current($lane->refresh(), $field);

        return 'Уже поменяли: '.mb_strtolower(Values::title($field)).' теперь '.($now['label'] ?? 'пусто');
    }
}
