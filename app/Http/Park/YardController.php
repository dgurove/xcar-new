<?php

namespace App\Http\Park;

use App\Cars\Settlement;
use App\Park\Yard;
use Illuminate\Http\Request;

class YardController
{
    public function index()
    {
        return view('park.yards', ['yards' => Yard::withCount('storedVehicles')->with(['settlement', 'storedVehicles:id,yard_id,spot,ref,accepted_at'])->orderByDesc('is_active')->orderBy('name')->get(),
            'settlements' => Settlement::orderByDesc('is_federal_city')->orderBy('name')->pluck('name', 'id')]);
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

        return array_merge($data, ['rows' => $rows, 'is_active' => $request->boolean('is_active', true)])
            + ($rows && empty($data['capacity']) ? ['capacity' => array_sum(array_column($rows, 'n'))] : []);
    }
}
