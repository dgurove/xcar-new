<?php

namespace App\Http\Park;

use App\Cars\Settlement;
use App\Park\Request as ParkRequest;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Users\User;
use App\Vendors\Tariff;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class YardController
{
    public function index(Request $request)
    {
        $closed = $request->boolean('closed');
        $yards = Yard::withCount('storedVehicles')->with(['settlement', 'storedVehicles:id,yard_id,spot,ref,accepted_at,brand_id', 'storedVehicles.brand'])->orderByDesc('is_active')->orderBy('name')->get();

        return view('park.yards', [
            'yards' => $closed ? $yards : $yards->where('is_active', true), 'closed' => $closed, 'closedCount' => $yards->where('is_active', false)->count(),
            'settlements' => Settlement::orderByDesc('is_federal_city')->orderBy('name')->pluck('name', 'id'),
        ]);
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
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:255'], 'settlement_id' => ['nullable', 'exists:settlements,id'], 'capacity' => ['nullable', 'integer', 'max:10000'], 'rows' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $rows = Yard::parseRows($data['rows'] ?? null);

        // Ряды заданы — вместимость считается по ним, иначе числом из поля.
        return array_merge($data, ['rows' => $rows, 'is_active' => $request->boolean('is_active', true)], $rows ? ['capacity' => array_sum(array_column($rows, 'n'))] : []);
    }

    /** Убрать площадку можно пустую и без истории; с ТС — только закрыть. */
    public function destroy(Yard $yard)
    {
        if (Vehicle::where('yard_id', $yard->id)->exists() || ParkRequest::where('yard_id', $yard->id)->exists() || Tariff::where('yard_id', $yard->id)->exists() || User::where('park_yard_id', $yard->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'На площадке есть ТС, заявки, прайс или сотрудники — закройте её']);
        }
        $yard->delete();

        return redirect('/yards')->with('toast', 'Площадка убрана');
    }
}
