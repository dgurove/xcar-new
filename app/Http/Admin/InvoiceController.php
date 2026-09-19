<?php

namespace App\Http\Admin;

use App\Billing\Actions\IssueInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Счёт покупателю по сделке из CRM: контрагент — покупатель (менеджер или его клиент), сумма — сделки, срок — этапа. */
class InvoiceController
{
    public function create(Request $request)
    {
        $offer = Offer::where('number', $request->query('offer'))->with(['deal.buyer.party', 'brand', 'model'])->firstOrFail();
        $deal = $offer->deal;
        abort_unless($deal && $deal->buyer, 404);
        $party = Party::forUser($deal->buyer);
        $position = $offer->position(Track::Sale);

        return view('admin.invoices.create', [
            'offer' => $offer, 'deal' => $deal, 'party' => $party,
            'parties' => Party::where('is_self', false)->orderBy('name')->pluck('name', 'id'),
            'existing' => Invoice::where('deal_id', $deal->id)->where('state', '!=', InvoiceState::Void)->get(),
            'due' => $position?->deadline_at?->toDateString() ?? now()->addDays(3)->toDateString(),
            'kinds' => [ChargeKind::Sale->value => ChargeKind::Sale->label(), ChargeKind::Selection->value => ChargeKind::Selection->label(), ChargeKind::Other->value => ChargeKind::Other->label()],
        ]);
    }

    public function store(Request $request, IssueInvoice $issue)
    {
        $offer = Offer::where('number', $request->query('offer'))->with('deal')->firstOrFail();
        $deal = $offer->deal;
        abort_unless($deal, 404);
        $data = $request->validate([
            'party_id' => ['required', 'exists:billing_parties,id'], 'kind' => ['required', 'in:sale,selection,other'], 'title' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:1'], 'due_at' => ['required', 'date'], 'vat' => ['boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $kind = ChargeKind::from($data['kind']);
        $invoice = $issue(Party::findOrFail($data['party_id']), $request->user(), 'issued', $kind, Carbon::parse($data['due_at']), $request->boolean('vat'),
            lines: [['title' => $data['title'], 'qty' => 1, 'unit' => 'pc', 'price' => (float) $data['amount'], 'kind' => $kind->value]],
            dealId: $deal->id, offerId: $offer->id, notes: $data['notes'] ?? null);
        $offer->log(OfferEventType::Note, $request->user(), ['text' => 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).' — '.$invoice->party->name]);

        return redirect("/offers/{$offer->number}")->with('toast', 'Счёт '.$invoice->label().' выставлен');
    }
}
