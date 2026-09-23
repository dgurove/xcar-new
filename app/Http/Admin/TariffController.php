<?php

namespace App\Http\Admin;

use App\Cars\Category;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Прайс: базовый на `/settings/tariffs`, договорной — строки с `vendor_id` из карточки вендора; форма одна. */
class TariffController
{
    public function index(Request $request)
    {
        return view('admin.tariffs', VendorController::tariffData($request, null) + ['base' => '/settings/tariffs']);
    }

    /**
     * Лестница услуги целиком одной формой: ступени по стоимости и по суткам вводят сразу все, как они стоят
     * в договоре. Цена не правится на месте — старая строка закрывается вчерашним днём, новая начинается
     * сегодняшним: прошлые дни и выставленные счета остаются по прежней цене.
     */
    public function save(Request $request)
    {
        // Строка без цены — ступени больше нет: и пустая новая, и та, у которой цену стёрли.
        $request->merge(['rows' => array_values(array_filter($request->input('rows', []), fn ($r) => ($r['price'] ?? '') !== ''))]);
        $data = $request->validate([
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'service' => ['required', Rule::enum(TariffService::class)],
            'rows' => ['array'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.price' => ['required', 'numeric', 'between:0,99999999'],
            'rows.*.from_day' => ['nullable', 'integer', 'between:1,3650'],
            'rows.*.from_value' => ['nullable', 'integer', 'between:0,999999999'],
            'rows.*.km_included' => ['nullable', 'integer', 'between:0,5000'],
            'rows.*.note' => ['nullable', 'string', 'max:120'],
        ]);
        $service = TariffService::from($data['service']);
        $where = ['vendor_id' => $data['vendor_id'] ?? null, 'yard_id' => $data['yard_id'] ?? null, 'category' => $data['category'] ?? null];
        $steps = collect($data['rows'] ?? [])->map(fn (array $r) => [
            'from_day' => $service->tiered() ? (int) ($r['from_day'] ?? 1) : 1,
            'from_value' => $service->tiered() && ($r['from_value'] ?? '') !== '' ? (int) $r['from_value'] : null,
            'km_included' => $service === TariffService::Tow ? ($r['km_included'] ?? null) : null,
            'price' => (float) $r['price'],
            'vat' => (bool) ($r['vat'] ?? false),
            'note' => ($r['note'] ?? '') === '' ? null : $r['note'],
            'id' => empty($r['id']) ? null : (int) $r['id'],
        ]);
        $cells = $steps->map(fn (array $s) => $s['from_day'].':'.$s['from_value']);
        if ($cells->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Две ступени с одними сутками и стоимостью — оставьте одну']);
        }

        $today = now()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $live = Tariff::query()->activeOn()->where($where)->where('service', $service)->get();
        // Сначала убираем снятые ступени, потом заводим новые: иначе новая заняла бы ячейку снятой в тот же день.
        foreach ($live->whereNotIn('id', $steps->pluck('id')->filter()->all()) as $gone) {
            $this->close($gone, $today, $yesterday);
        }
        foreach ($steps as $step) {
            $was = $step['id'] ? $live->firstWhere('id', $step['id']) : null;
            unset($step['id']);
            if ($was && $this->same($was, $step)) {
                continue;
            }
            if ($was) {
                $this->close($was, $today, $yesterday);
            }
            Tariff::create($step + $where + ['service' => $service, 'valid_from' => $today->toDateString()]);
        }

        return back()->with('toast', 'Прайс сохранён')->with('sheet', $data['category'] ?? 'any');
    }

    /** Ступень действовала раньше — закрываем вчерашним днём; заведена сегодня и ещё ничего не считала — убираем. */
    private function close(Tariff $tariff, Carbon $today, Carbon $yesterday): void
    {
        $tariff->valid_from->lt($today) ? $tariff->update(['valid_to' => $yesterday->toDateString()]) : $tariff->delete();
    }

    /** @param  array<string, mixed>  $step */
    private function same(Tariff $was, array $step): bool
    {
        return abs($was->price - $step['price']) < 0.005 && $was->vat === $step['vat'] && $was->from_day === (int) $step['from_day']
            && $was->from_value === $step['from_value'] && (int) $was->km_included === (int) $step['km_included'] && $was->note === $step['note'];
    }
}
