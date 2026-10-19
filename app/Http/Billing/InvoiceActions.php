<?php

namespace App\Http\Billing;

use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Support\Nav;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Действия со счётом — одни на парковку и CRM (16.10.2026: оба `MoneyController` держали их копией): страница счёта,
 * оплата и её отмена, платёжка, правка срока, аннулирование, PDF, печать и акт хранения. Адреса у каждой стороны свои
 * (`routes/park.php`, `routes/crm.php`), действия возвращают назад. Сторона говорит, какие счета ей видны
 * (`guardInvoice`), каким экраном показывать счёт (`invoiceView`) и как назвать исход (`invoiceToasts`).
 */
trait InvoiceActions
{
    /** Экран счёта стороны — обёртка над `billing.invoice-page` со своими адресами. */
    abstract protected function invoiceView(): string;

    /**
     * Чем сторона отличается словами: owedPaid и owedRecorded — исход оплаты «мы должны» (парковка перечисляет вендору,
     * CRM выплачивает менеджеру), unpaid и voided — отмена оплаты и аннулирование.
     *
     * @return array{owedPaid: string, owedRecorded: string, unpaid: string, voided: string}
     */
    abstract protected function invoiceToasts(): array;

    /** Чужой счёт — 404. По умолчанию видны все. */
    protected function guardInvoice(Invoice $invoice): void {}

    public function show(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        $invoice->load(['party', 'vehicle', 'vehicle.yard', 'charges', 'payments.media', 'claims.media', 'allPayments', 'deal.offer', 'deal.buyer', 'creator', 'media']);

        return view($this->invoiceView(), ['invoice' => $invoice, 'sources' => PaymentSource::manual(), 'file' => $invoice->getFirstMedia('file')]);
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $record)
    {
        $this->guardInvoice($invoice);
        // Номер — у банка и эквайринга, скан платёжки — только у банка: у наличных их нет (19.10.2026, владелец).
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['nullable', 'date'], 'source' => ['required', Rule::enum(PaymentSource::class)], 'ref' => ['exclude_unless:source,bank,acquiring', 'nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255'], 'slip' => ['exclude_unless:source,bank', 'nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic']]);
        $payment = $record($invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, PaymentSource::from($data['source']), $data['ref'] ?? null, $data['note'] ?? null);
        if (isset($data['slip'])) {
            $payment->addMediaFromRequest('slip')->toMediaCollection('slip');
        }
        $paid = $invoice->fresh()->state === InvoiceState::Paid;
        $say = $this->invoiceToasts();

        return back()->with('toast', $invoice->isOwed() ? ($paid ? $say['owedPaid'] : $say['owedRecorded']) : ($paid ? 'Оплачен' : 'Оплата записана'));
    }

    /** Скан платёжки — с закрытого диска, только своим. */
    public function slip(Invoice $invoice, Payment $payment)
    {
        $this->guardInvoice($invoice);
        abort_unless($payment->invoice_id === $invoice->id && ($media = $payment->slip()), 404);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    /** Срок, чужой номер и заметка правятся у выставленного счёта; суммы и строки — нет. */
    public function update(Request $request, Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        abort_unless($invoice->state === InvoiceState::Issued, 404);
        $data = $request->validate(['due_at' => ['required', 'date'], 'external_no' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $invoice->update(['due_at' => Carbon::parse($data['due_at'])->toDateString(), 'external_no' => $data['external_no'] ?: null, 'notes' => $data['notes'] ?: null, 'overdue_at' => Carbon::parse($data['due_at'])->isFuture() ? null : $invoice->overdue_at]);
        Nav::forgetStaffCounts();

        return back()->with('toast', 'Сохранено');
    }

    public function unpay(Request $request, Invoice $invoice, Payment $payment, VoidPayment $void)
    {
        $this->guardInvoice($invoice);
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $void($payment, $request->user(), $request->input('reason'));

        return back()->with('toast', $this->invoiceToasts()['unpaid']);
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $void)
    {
        $this->guardInvoice($invoice);
        $void($invoice, $request->user(), $request->input('reason'));

        return back()->with('toast', $this->invoiceToasts()['voided']);
    }

    public function file(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        $media = $invoice->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    public function print(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        $invoice->load(['party', 'vehicle', 'charges', 'payments', 'deal.offer']);

        return view('billing.docs.invoice', ['invoice' => $invoice, 'self' => $invoice->seller->party(), 'pdf' => false]);
    }

    /** Акт хранения: PDF, если выставлен закрытием месяца, иначе страница на печать (у реестра — отчёт-акт). */
    public function act(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        if ($media = $invoice->getFirstMedia('act')) {
            return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
        }
        $invoice->load(['party', 'vehicle', 'vehicle.yard', 'charges.vehicle', 'charges.vehicle.yard']);

        return view($invoice->isRegistry() ? 'billing.docs.storage-registry' : 'billing.docs.storage-act', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }
}
