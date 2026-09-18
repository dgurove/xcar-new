<?php

namespace App\Http\Admin;

use App\Cars\Category;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Прайс: базовый на `/settings/tariffs`, договорной — строки с `vendor_id` из карточки вендора; форма одна. */
class TariffController
{
    public function index(Request $request)
    {
        return view('admin.tariffs', VendorController::tariffData($request, null) + ['base' => '/settings/tariffs']);
    }

    public function store(Request $request)
    {
        Tariff::create($this->data($request));

        return back()->with('toast', 'Цена добавлена');
    }

    public function update(Request $request, Tariff $tariff)
    {
        $tariff->update($this->data($request));

        return back()->with('toast', 'Сохранено');
    }

    public function destroy(Tariff $tariff)
    {
        $tariff->delete();

        return back()->with('toast', 'Строка убрана');
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'service' => ['required', Rule::enum(TariffService::class)],
            'from_day' => ['nullable', 'integer', 'between:1,3650'],
            'km_included' => ['nullable', 'integer', 'between:0,5000'],
            'price' => ['required', 'numeric', 'between:0,99999999'],
            'vat' => ['boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);
        $service = TariffService::from($data['service']);

        return array_merge($data, [
            'from_day' => $service->tiered() ? (int) ($data['from_day'] ?? 1) : 1,
            'km_included' => $service === TariffService::Tow ? ($data['km_included'] ?? null) : null,
            'valid_from' => $data['valid_from'] ?? now()->toDateString(),
            'vat' => $request->boolean('vat'),
        ]);
    }
}
