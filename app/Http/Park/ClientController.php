<?php

namespace App\Http\Park;

use App\Park\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController
{
    public function index()
    {
        return view('park.clients', ['clients' => Client::withCount('vehicles')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Client::create($this->data($request));

        return redirect('/klienty')->with('toast', 'Добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->data($request, $client));

        return redirect('/klienty')->with('toast', 'Сохранено');
    }

    private function data(Request $request, ?Client $client = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('park_clients', 'name')->ignore($client?->id)],
            'contact_name' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'],
            'sender_domains' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'name' => $data['name'],
            'contacts' => array_filter([['name' => $data['contact_name'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null]], fn ($c) => array_filter($c)),
            'sender_domains' => array_values(array_filter(array_map(fn ($d) => mb_strtolower(trim($d)), preg_split('/[\s,;]+/', (string) ($data['sender_domains'] ?? ''))))),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
