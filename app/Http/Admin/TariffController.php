<?php

namespace App\Http\Admin;

use App\Cars\Category;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * в договоре, и одной датой «Действует с». Цена не правится на месте — старая строка закрывается днём
     * раньше новой: прошлые дни и выставленные счета остаются по прежней цене.
     */
    public function save(Request $request)
    {
        // Строка без цены — ступени больше нет: и пустая новая, и та, у которой цену стёрли.
        $rows = array_filter(Arr::wrap($request->input('rows', [])), fn ($r) => is_array($r) && ($r['price'] ?? '') !== '');
        $request->merge(['rows' => array_values($rows)]);
        $data = $request->validate([
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'service' => ['required', Rule::enum(TariffService::class)],
            // Пусто — форма новой услуги; иначе услуга той лестницы, которую правят.
            'ladder' => ['nullable', Rule::enum(TariffService::class)],
            'valid_from' => ['nullable', 'date'],
            // Снять лестницу целиком — только своей кнопкой: пустая отправка не должна стирать цены.
            'drop' => ['nullable', 'boolean'],
            'rows' => ['array'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.price' => ['required', 'numeric', 'between:0,99999999'],
            'rows.*.from_day' => ['nullable', 'integer', 'between:1,3650'],
            'rows.*.from_value' => ['nullable', 'integer', 'between:0,999999999'],
            'rows.*.km_included' => ['nullable', 'integer', 'between:0,5000'],
            'rows.*.vat' => ['boolean'],
            'rows.*.note' => ['nullable', 'string', 'max:120'],
        ]);
        $service = TariffService::from($data['service']);
        $fresh = ($data['ladder'] ?? '') === '';
        // Ошибка ложится на свою форму: и шторок, и форм в них несколько, общий ключ показал бы её во всех.
        $key = 'ladder.'.($data['category'] ?? 'any').'.'.($fresh ? 'new' : $service->value);
        $where = ['vendor_id' => $data['vendor_id'] ?? null, 'yard_id' => $data['yard_id'] ?? null, 'category' => $data['category'] ?? null];
        $drop = $request->boolean('drop');
        $steps = $drop ? collect() : collect($data['rows'] ?? [])->map(fn (array $r) => [
            'from_day' => $service->tiered() ? (int) ($r['from_day'] ?? 1) : 1,
            'from_value' => $service->tiered() && ($r['from_value'] ?? '') !== '' ? (int) $r['from_value'] : null,
            'km_included' => $service === TariffService::Tow ? ($r['km_included'] ?? null) : null,
            'price' => (float) $r['price'],
            'vat' => (bool) ($r['vat'] ?? false),
            'note' => ($r['note'] ?? '') === '' ? null : $r['note'],
            'id' => empty($r['id']) ? null : (int) $r['id'],
        ]);
        if ($steps->map(fn (array $s) => $s['from_day'].':'.$s['from_value'])->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([$key => 'Две ступени с одними сутками и стоимостью — оставьте одну']);
        }
        // Ни одной цены — либо пустая форма новой услуги, либо сбой: цены стираются только кнопкой «Убрать».
        if (! $drop && $steps->isEmpty()) {
            throw ValidationException::withMessages([$key => 'Впишите цену хотя бы одной ступени']);
        }

        // «Действует с»: с этого дня живут присланные цены, прежние закрываются днём раньше.
        $from = Carbon::parse($data['valid_from'] ?? now())->startOfDay();
        $live = Tariff::query()->activeOn()->where($where)->where('service', $service)->get();
        // Форма новой услуги не имеет права переписать заведённую лестницу: одна строка в ней стёрла бы все
        // ступени. Выбор услуг в ней и так сужен — это защита от устаревшей страницы.
        if ($fresh && $live->isNotEmpty()) {
            throw ValidationException::withMessages([$key => 'Лестница «'.mb_strtolower($service->label()).'» уже заведена — правьте её выше']);
        }

        try {
            // Одной транзакцией: падение между «закрыть» и «создать» оставило бы ячейку без действующей цены,
            // и все ТС вендора считались бы нулём.
            DB::transaction(function () use ($live, $steps, $where, $service, $from) {
                // Сначала убираем снятые ступени, потом заводим новые: иначе новая заняла бы ячейку снятой.
                foreach ($live->whereNotIn('id', $steps->pluck('id')->filter()->all()) as $gone) {
                    $this->close($gone, $from);
                }
                foreach ($steps as $step) {
                    $was = $step['id'] ? $live->firstWhere('id', $step['id']) : null;
                    unset($step['id']);
                    if ($was && $this->same($was, $step)) {
                        continue;
                    }
                    if ($was) {
                        $this->close($was, $from);
                    }
                    Tariff::create($step + $where + ['service' => $service, 'valid_from' => $from->toDateString()]);
                }
            });
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'park_tariffs_cell')) {
                throw $e;
            }
            // Та же ступень с той же датой уже лежит в истории цен — руками её не разгрести, нужна другая дата.
            throw ValidationException::withMessages(['valid_from' => 'Цена с '.$from->format('d.m.Y').' по этой ступени уже заводилась — возьмите другую дату']);
        }

        return back()->with('toast', $drop ? 'Лестница убрана' : 'Прайс сохранён')->with('sheet', $data['category'] ?? 'any');
    }

    /** Ступень действовала до новой цены — закрываем днём раньше; начиналась с неё же или позже — убираем. */
    private function close(Tariff $tariff, Carbon $from): void
    {
        $tariff->valid_from->lt($from) ? $tariff->update(['valid_to' => $from->copy()->subDay()->toDateString()]) : $tariff->delete();
    }

    /** @param  array<string, mixed>  $step */
    private function same(Tariff $was, array $step): bool
    {
        return abs($was->price - $step['price']) < 0.005 && $was->vat === $step['vat'] && $was->from_day === (int) $step['from_day']
            && $was->from_value === $step['from_value'] && (int) $was->km_included === (int) $step['km_included'] && $was->note === $step['note'];
    }
}
