<?php

namespace App\Http\Park;

use App\Park\Actions\SavePlan;
use App\Park\Request as ParkRequest;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Detail;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Vendors\Tariff;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class YardController
{
    /** Справочник — по имени (владелец 06.10.2026), открытые первыми. */
    public const SORTS = ['name' => ['Имя', 'asc'], 'created' => ['Дата добавления', 'desc'], 'stored' => ['Занято', 'desc'], 'free' => ['Свободно', 'desc']];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($yard = Yard::find($key)) ? $this->detail($yard) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        ListPrefs::sync($request, 'park-yards');
        $closed = $request->query('closed') === 'all' || $request->boolean('closed');
        $sort = Sort::from($request->query('sort'), self::SORTS, 'name');
        $yards = Yard::withCount('storedVehicles')->with(['settlement', 'storedVehicles:id,car_id,yard_id,spot,accepted_at', 'storedVehicles.car'])->orderByDesc('is_active')
            ->when($sort->key === 'free', fn ($y) => $y->orderByRaw("(coalesce(capacity, 0) - (select count(*) from park_vehicles v where v.yard_id = park_yards.id and v.state = 'stored')) {$sort->dir()}"))
            ->orderBy(match ($sort->key) {
                'created' => 'created_at', 'stored' => 'stored_vehicles_count', default => 'name'
            }, $sort->key === 'free' ? 'asc' : $sort->dir())->get();

        return view('park.yards.index', [
            'detail' => $detail,
            'yards' => $closed ? $yards : $yards->where('is_active', true), 'closed' => $closed, 'closedCount' => $yards->where('is_active', false)->count(),
            'sort' => $sort,
        ]);
    }

    /** Карточка строки таблицы: карта мест и форма. */
    public function detail(Yard $yard)
    {
        $yard->loadCount('storedVehicles')->load(['settlement', 'storedVehicles:id,car_id,yard_id,spot,accepted_at', 'storedVehicles.car']);

        return view('park.yards.detail', ['yard' => $yard]);
    }

    /**
     * Схема парковки: ТС на местах, «без места» (места нет или оно не из схемы) лотком под схемой. `?pick=` — только схема
     * для выбора места в шторке (`move-sheet`, приём): фрейм `plan-pick`, отмечено место `?at=`.
     */
    public function plan(Request $request, Yard $yard)
    {
        $cars = Vehicle::where('yard_id', $yard->id)->where('state', VehicleState::Stored)->with('car.brand', 'car.model')->orderBy('accepted_at')->get();
        $spots = $yard->spots();
        $placed = $cars->filter(fn (Vehicle $v) => $v->spot && in_array($v->spot, $spots, true))->keyBy('spot');
        if ($request->has('pick')) {
            return view('park.yards.plan-pick', ['yard' => $yard, 'cars' => $placed, 'at' => $request->query('at'), 'vehicle' => (int) $request->query('pick')]);
        }

        return view('park.yards.plan', [
            'yard' => $yard, 'cars' => $placed, 'loose' => $cars->reject(fn (Vehicle $v) => $placed->has($v->spot) && $placed[$v->spot]->id === $v->id)->values(),
            'edits' => $request->user()->editsYardPlan(),
        ]);
    }

    /** Редактор схемы: ряды и занятые места (их не убрать; марка — для подсказки, тип — значком) — данными для `yard_editor_controller`. */
    public function edit(Yard $yard)
    {
        $taken = Vehicle::where('yard_id', $yard->id)->where('state', VehicleState::Stored)->whereNotNull('spot')->with('car.brand')->get()
            ->mapWithKeys(fn (Vehicle $v) => [$v->spot => ['label' => $v->brand?->name ?? $v->ref ?? 'ТС', 'cat' => $v->category?->value]]);

        return view('park.yards.plan-edit', ['yard' => $yard, 'rows' => $yard->plan(), 'taken' => $taken, 'rev' => $yard->planRev()]);
    }

    /** Сохранить схему из редактора (`SavePlan`): ответ — новая ревизия, ошибка — 422 словами. */
    public function save(Request $request, Yard $yard, SavePlan $save)
    {
        $data = $request->validate(['rows' => ['present', 'array'], 'rev' => ['required', 'string']]);
        $yard = $save($yard, $data['rows'], $data['rev']);

        return response()->json(['rev' => $yard->planRev(), 'capacity' => $yard->capacity]);
    }

    public function store(Request $request)
    {
        $yard = Yard::create($this->data($request));

        return redirect('/yards')->with('toast', "«{$yard->name}» добавлена");
    }

    public function update(Request $request, Yard $yard)
    {
        $yard->update($this->data($request));

        return redirect('/yards')->with('toast', 'Сохранено');
    }

    private function data(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:255'], 'settlement_id' => ['nullable', 'exists:settlements,id'], 'capacity' => ['nullable', 'integer', 'max:10000'], 'notes' => ['nullable', 'string', 'max:2000']]);
        // Есть схема — вместимость считает она (`SavePlan`), поле «Мест» её не перебивает.
        if ($request->route('yard')?->rows) {
            unset($data['capacity']);
        }

        return array_merge($data, ['is_active' => $request->boolean('is_active', true)]);
    }

    /** Убрать площадку можно пустую и без истории; с ТС — только закрыть. */
    public function destroy(Yard $yard)
    {
        if (Vehicle::where('yard_id', $yard->id)->exists() || ParkRequest::where('yard_id', $yard->id)->exists() || Tariff::where('yard_id', $yard->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'На парковке есть ТС, заявки или прайс, закройте её']);
        }
        $yard->delete();

        return redirect('/yards')->with('toast', 'Парковка убрана');
    }
}
