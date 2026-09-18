<?php

namespace App\Http\Park;

use App\Support\Surface;
use App\Vendors\Contact;
use App\Vendors\ContactRole;
use App\Vendors\DocRequirement;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Вендоры глазами стоянки: список тех же компаний, правится только стояночное — контакт по хранению, что прислать после приёма, заметки. Остальное — карточка в CRM. */
class ClientController
{
    public function index()
    {
        return view('park.clients', [
            'vendors' => Vendor::with('contacts')->withCount(['vehicles', 'vehicles as stored_count' => fn ($q) => $q->where('state', 'stored')])
                ->orderByDesc('is_active')->orderBy('name')->get(),
            'docs' => DocRequirement::cases(),
            'crm' => Surface::Crm->url('/settings/vendors'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:vendors,name']]);
        $vendor = Vendor::create($data);

        return redirect('/clients')->with('toast', 'Добавлен');
    }

    public function update(Request $request, Vendor $client)
    {
        $data = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'],
            'intake_docs' => ['nullable', 'array'], 'intake_docs.*' => [Rule::enum(DocRequirement::class)],
            'intake_note' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $client->update(['intake_docs' => array_values($data['intake_docs'] ?? []), 'intake_note' => $data['intake_note'] ?? null, 'notes' => $data['notes'] ?? null]);
        $contact = $client->contacts->firstWhere('role', ContactRole::Storage);
        $fields = ['name' => $data['contact_name'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null];
        if (array_filter($fields)) {
            $contact ? $contact->update($fields) : Contact::create($fields + ['vendor_id' => $client->id, 'role' => ContactRole::Storage, 'name' => $fields['name'] ?: 'Хранение']);
        } elseif ($contact) {
            $contact->delete();
        }

        return redirect('/clients')->with('toast', 'Сохранено');
    }
}
