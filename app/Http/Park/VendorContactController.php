<?php

namespace App\Http\Park;

use App\Vendors\Contact;
use App\Vendors\ContactRole;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Контакты вендора на парковке — убытки, хранение, бухгалтерия. «Реализацию» ведёт CRM
 * (`Admin\VendorContactController`, та же логика с `$sale = true`): чужих контактов сторона не видит и не правит.
 */
class VendorContactController
{
    protected bool $sale = false;

    public function store(Request $request, Vendor $vendor)
    {
        $contact = $vendor->contacts()->create($this->data($request));
        $this->single($vendor, $contact);

        return back()->with('toast', 'Контакт добавлен');
    }

    public function update(Request $request, Vendor $vendor, Contact $contact)
    {
        $this->own($vendor, $contact);
        $contact->update($this->data($request));
        $this->single($vendor, $contact);

        return back()->with('toast', 'Сохранено');
    }

    public function destroy(Vendor $vendor, Contact $contact)
    {
        $this->own($vendor, $contact);
        $contact->delete();

        return back()->with('toast', 'Контакт удалён');
    }

    private function own(Vendor $vendor, Contact $contact): void
    {
        abort_unless($contact->vendor_id === $vendor->id && $contact->role->isSale() === $this->sale, 404);
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_map(fn (ContactRole $r) => $r->value, ContactRole::side($this->sale)))],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'yard_id' => [Rule::prohibitedIf($this->sale), 'nullable', 'exists:park_yards,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $data + ['always_cc' => $request->boolean('always_cc'), 'is_default' => $request->boolean('is_default')];
    }

    /** Основной — один на сторону: у реализации и у парковки свой. */
    private function single(Vendor $vendor, Contact $contact): void
    {
        if ($contact->is_default) {
            $vendor->contacts()->where('id', '!=', $contact->id)
                ->whereIn('role', array_map(fn (ContactRole $r) => $r->value, ContactRole::side($this->sale)))
                ->update(['is_default' => false]);
        }
    }
}
