<?php

namespace App\Http\Park;

use App\Park\Yard;
use Illuminate\Http\Request;

class YardController
{
    public function index()
    {
        return view('park.yards', ['yards' => Yard::withCount('storedVehicles')->orderByDesc('is_active')->orderBy('name')->get()]);
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
        return $request->validate(['name' => ['required', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:255'], 'capacity' => ['nullable', 'integer', 'max:10000'], 'notes' => ['nullable', 'string', 'max:2000']])
            + ['is_active' => $request->boolean('is_active', true)];
    }
}
