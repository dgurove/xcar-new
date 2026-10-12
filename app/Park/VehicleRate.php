<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Cars\Category;
use App\Support\Money;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ставка машины одним местом (12.10.2026, владелец: «не понимаю, как на парковке ставку указывать», «единоразово: для этой
 * машины ставка такая — в редакторе, в окошке, при приёме и в наличии»): тип ТС, своя ставка за сутки (пусто — по тарифу
 * вендора), почему своя и оценочная — то, от чего ставка зависит. Кусок фразы и шторка — `x-park.rate`, сохраняет
 * `VehicleController::rate`, при приёме — форма приёма. Своя ставка действует на всё время стоянки, выставленные дни не
 * трогаются (`Accrual`).
 */
final class VehicleRate
{
    public const KEYS = ['category', 'storage_rate', 'storage_rate_note', 'value'];

    /** Поля из формы: суммы — без пробелов разрядов («1 200», «120,5»), пусто — снять. Только пришедшие ключи. */
    public static function from(Request $request): array
    {
        foreach (['storage_rate', 'value'] as $key) {
            if ($request->has($key)) {
                $raw = str_replace([' ', ' ', ','], ['', '', '.'], (string) $request->input($key));
                $request->merge([$key => $raw === '' ? null : $raw]);
            }
        }
        $data = $request->validate([
            'category' => ['nullable', Rule::enum(Category::class)],
            'storage_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'storage_rate_note' => ['nullable', 'string', 'max:120'],
            'value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        return array_intersect_key($data, array_flip(array_filter(self::KEYS, fn ($k) => $request->has($k))));
    }

    /** Подсказка в поле своей ставки — коротко: «по тарифу 350», «по стоимости 250…450», «тарифа нет». */
    public static function hint(Vehicle $vehicle): string
    {
        $ladder = Tariff::ladderFor($vehicle, TariffService::Storage);
        $prices = $ladder->pluck('price')->filter()->map(fn ($p) => (int) round($p))->unique()->sort()->values();
        if ($prices->isEmpty()) {
            return 'тарифа нет';
        }

        return $prices->count() > 1 ? 'по тарифу '.Money::nums($prices->first()).'…'.Money::nums($prices->last()) : 'по тарифу '.Money::nums($prices->first());
    }

    /**
     * Что писать во фразе: своя — «300 ₽/сут, своя», по тарифу — ставка сегодня (до приёма — первая ступень), нет — null.
     *
     * @return array{text: ?string, own: bool}
     */
    public static function shown(Vehicle $vehicle): array
    {
        if ($vehicle->storage_rate !== null) {
            return ['text' => Money::nums((int) round($vehicle->storage_rate)).' ₽/сут', 'own' => true];
        }
        $rate = $vehicle->accepted_at ? Accrual::rateToday($vehicle) : Tariff::ladderFor($vehicle, TariffService::Storage)->first()?->price;

        return ['text' => $rate !== null ? Money::nums((int) round($rate)).' ₽/сут' : null, 'own' => false];
    }
}
