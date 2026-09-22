<?php

namespace App\Http\Admin;

use App\Billing\Actions\IssueDealInvoice;
use App\Billing\ChargeKind;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\Offer;
use App\Vendors\RewardKind;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Счёт по сделке из CRM: плательщик — менеджер, его покупатель или вендор (за
 * вознаграждение), база — по виду, строки собирает `IssueDealInvoice`:
 * «Транспортное средство» и «Агентское вознаграждение».
 */
class InvoiceController
{
    public function create(Request $request)
    {
        $offer = Offer::where('number', $request->query('offer'))->with(['deal.buyer.party', 'brand', 'model', 'vendor'])->firstOrFail();
        $deal = $offer->deal;
        abort_unless($deal && $deal->buyer, 404);
        $party = Party::forUser($deal->buyer);
        $position = $offer->position(Track::Sale);
        $vendor = $offer->vendor;
        $vendorParty = $vendor && $vendor->kind->billable() ? Party::forVendor($vendor, false) : null;

        return view('admin.invoices.create', [
            'offer' => $offer, 'deal' => $deal, 'party' => $party,
            'parties' => Party::where('is_self', false)->orderBy('name')->pluck('name', 'id')->put('new', 'Новый плательщик'),
            'vendorParty' => $vendorParty,
            'existing' => $deal->invoices()->with('party')->get(),
            'due' => $position?->deadline_at?->toDateString() ?? now()->addDays(3)->toDateString(),
            'kinds' => [ChargeKind::Sale->value => ChargeKind::Sale->label(), ChargeKind::Selection->value => ChargeKind::Selection->label(), ChargeKind::Reward->value => ChargeKind::Reward->label(), ChargeKind::Other->value => ChargeKind::Other->label()],
            // База по виду: продажа — цена подтверждения, подбор — разница, вознаграждение — по условиям вендора.
            'bases' => [
                'sale' => $deal->amount,
                'selection' => max(0, (int) $deal->margin()),
                'reward' => $this->reward($deal->amount, $deal->cost, $vendor?->reward_kind, $vendor?->reward_value),
                'other' => $deal->amount,
            ],
            'partyKinds' => PartyKind::options(),
        ]);
    }

    public function store(Request $request, IssueDealInvoice $issue)
    {
        $offer = Offer::where('number', $request->query('offer'))->with('deal')->firstOrFail();
        $deal = $offer->deal;
        abort_unless($deal, 404);
        $data = $request->validate([
            'party_id' => ['required', Rule::when(fn () => $request->input('party_id') !== 'new', ['exists:billing_parties,id'])],
            'party_name' => ['required_if:party_id,new', 'nullable', 'string', 'max:200'], 'party_kind' => ['nullable', Rule::enum(PartyKind::class)],
            'party_inn' => ['nullable', 'digits_between:10,12'], 'party_phone' => ['nullable', 'string', 'max:20'],
            'kind' => ['required', 'in:sale,selection,reward,other'], 'title' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:1'], 'due_at' => ['required', 'date'], 'vat' => ['boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $party = $data['party_id'] === 'new'
            ? Party::create(['kind' => PartyKind::tryFrom($data['party_kind'] ?? '') ?? PartyKind::Person, 'name' => $data['party_name'], 'inn' => $data['party_inn'] ?? null, 'phone' => $data['party_phone'] ?? null])
            : Party::findOrFail($data['party_id']);
        $invoice = $issue($deal, $request->user(), $party, ChargeKind::from($data['kind']), (float) $data['amount'], Carbon::parse($data['due_at']), $request->boolean('vat'),
            $data['notes'] ?? null, filled($data['title'] ?? null) ? $data['title'] : null);

        return redirect("/offers/{$offer->number}")->with('toast', 'Счёт '.$invoice->label().' выставлен');
    }

    /** Наше вознаграждение по условиям вендора: разница цен, процент от продажи или фикс; без условий — разница. */
    private function reward(int $amount, ?int $cost, ?RewardKind $kind, ?int $value): int
    {
        return max(0, (int) match ($kind) {
            RewardKind::Percent => round($amount * ($value ?? 0) / 100),
            RewardKind::Fixed => $value ?? 0,
            default => $cost === null ? 0 : $amount - $cost,
        });
    }
}
