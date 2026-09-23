<?php

namespace App\Http\Park;

use App\Support\Surface;
use App\Vendors\Contact;
use App\Vendors\ContactRole;
use App\Vendors\DocRequirement;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Вендоры глазами стоянки: список тех же компаний, правится только стояночное — контакт по хранению, что прислать после приёма, заметки. Остальное — карточка в CRM. */
class ClientController
{
    public const PRESETS = ['active' => 'Работаем', 'stored' => 'С ТС на парковке', 'inactive' => 'Не работаем'];

    public function index(Request $request)
    {
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'active';
        $q = trim((string) $request->query('q'));
        $kind = Kind::tryFrom((string) $request->query('kind'));
        $vendors = Vendor::with(['contacts', 'party'])->when($kind, fn ($w) => $w->where('kind', $kind))->withCount(['vehicles', 'vehicles as stored_count' => fn ($q) => $q->where('state', 'stored')])
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'ilike', "%{$q}%")->orWhere('legal_name', 'ilike', "%{$q}%")->orWhere('inn', 'like', "%{$q}%")))
            ->orderBy('name')->get();
        $counts = ['active' => $vendors->where('is_active', true)->count(), 'stored' => $vendors->where('stored_count', '>', 0)->count(), 'inactive' => $vendors->where('is_active', false)->count()];

        return view('park.clients', [
            'vendors' => match ($preset) {
                'stored' => $vendors->where('stored_count', '>', 0), 'inactive' => $vendors->where('is_active', false), default => $vendors->where('is_active', true)
            },
            'preset' => $preset, 'presets' => self::PRESETS, 'counts' => $counts, 'q' => $q, 'kind' => $kind,
            'docs' => DocRequirement::cases(),
            'crm' => Surface::Crm->url('/settings/vendors'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:vendors,name'], 'kind' => ['required', Rule::enum(Kind::class)]]);
        $vendor = Vendor::create($data);

        return redirect('/clients')->with('toast', 'Добавлен');
    }

    public function update(Request $request, Vendor $client)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(Kind::class)],
            'contact_name' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'],
            'intake_docs' => ['nullable', 'array'], 'intake_docs.*' => [Rule::enum(DocRequirement::class)],
            'intake_note' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $client->update(['kind' => $data['kind'], 'intake_docs' => array_values($data['intake_docs'] ?? []), 'intake_note' => $data['intake_note'] ?? null, 'notes' => $data['notes'] ?? null]);
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
