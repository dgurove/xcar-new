<?php

namespace App\Http\Park;

use App\Vendors\Contact;
use App\Vendors\ContactRole;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorContactController
{
    public function store(Request $request, Vendor $vendor)
    {
        $contact = $vendor->contacts()->create($this->data($request));
        $this->single($vendor, $contact);

        return back()->with('toast', 'Контакт добавлен');
    }

    public function update(Request $request, Vendor $vendor, Contact $contact)
    {
        abort_unless($contact->vendor_id === $vendor->id, 404);
        $contact->update($this->data($request));
        $this->single($vendor, $contact);

        return back()->with('toast', 'Сохранено');
    }

    public function destroy(Vendor $vendor, Contact $contact)
    {
        abort_unless($contact->vendor_id === $vendor->id, 404);
        $contact->delete();

        return back()->with('toast', 'Контакт удалён');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::enum(ContactRole::class)],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]) + ['always_cc' => $request->boolean('always_cc'), 'is_default' => $request->boolean('is_default')];
    }

    /** Основной — один. */
    private function single(Vendor $vendor, Contact $contact): void
    {
        if ($contact->is_default) {
            $vendor->contacts()->where('id', '!=', $contact->id)->update(['is_default' => false]);
        }
    }
}
