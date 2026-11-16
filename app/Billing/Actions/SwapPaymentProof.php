<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Offers\Deal;
use App\Offers\Documents\PaymentProofPdf;
use App\Offers\OfferEventType;
use App\Support\Ago;
use App\Support\Money;
use App\Support\Nav;
use App\Users\User;
use App\Workflow\Actions\AttachRequirementFiles;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * «Это за другое» в CRM (16.11.2026, владелец по 2611134025: менеджер приложил чек Альфы на 2,7 млн к оплате нашего подбора на
 * 88 190 ₽): у «страховой напрямую» и ДКП два платежа — поставщику (платёжка шага оплаты, её получает страховая) и наш счёт
 * ООО «ПРАЙМ» (сообщение об оплате). Чек лёг не туда — переложить одним нажатием.
 *  - `toSupplier`: сообщение об оплате ПРАЙМ → платёжка поставщику (PDF; открытый шаг оплаты проходит ответом менеджера),
 *    само сообщение удаляется — это была не оплата ПРАЙМ.
 *  - `toInvoice`: платёжка поставщику (пока письмо страховой не ушло) → сообщение об оплате ПРАЙМ на остаток, шаг снова
 *    «Оплата» и ждёт чек поставщику.
 */
final class SwapPaymentProof
{
    public function __construct(private AttachRequirementFiles $attach, private TakeExit $take, private ClaimPayment $claim) {}

    /** Куда ляжет чек поставщику: открытая просьба шага оплаты поставщику или ушедшая, ещё не подтверждённая платёжка. */
    public static function supplierTarget(Payment $payment): ?Requirement
    {
        $invoice = $payment->invoice;
        $deal = $invoice?->deal;
        if ($payment->state !== PaymentState::Claimed || $invoice->kind !== ChargeKind::Selection || ! $deal?->isLive() || ! $deal->paysSelection()) {
            return null;
        }
        $open = $deal->openRequirement;
        if ($open && AttachRequirementFiles::paysOn($open) && $deal->paysSupplierOn($open->stage)) {
            return $open;
        }

        return $deal->sentPaymentProof();
    }

    /** Счёт ПРАЙМ, к которому можно переложить платёжку поставщику: шаг ждёт страховую, письмо ей не ушло, остаток не заявлен. */
    public static function invoiceTarget(Deal $deal): ?Invoice
    {
        $position = $deal->offer?->position(Track::Sale);
        if (! $deal->isLive() || ! $deal->paysSelection() || ! $deal->sentPaymentProof() || $position?->stage->waits_for !== WaitsFor::Supplier
            || $position->letterStatus() === 'sent') {
            return null;
        }

        return $deal->issuedInvoices()->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)->with('claims')->get()
            ->first(fn (Invoice $i) => $i->remaining() - $i->claimed() > 0);
    }

    public function toSupplier(Payment $payment, User $by): void
    {
        $requirement = self::supplierTarget($payment) ?? throw ValidationException::withMessages(['payment' => 'Оплату поставщику сейчас не приложить']);
        $slip = $payment->slip() ?? throw ValidationException::withMessages(['payment' => 'У сообщения нет чека']);
        $invoice = $payment->invoice;
        $deal = $invoice->deal;
        DB::transaction(function () use ($requirement, $slip, $payment, $by, $deal) {
            $this->attachCopy($requirement, $slip);
            if (! $requirement->done_at && ($exit = $deal->proofMove($requirement->stage))) {
                Requirement::closeOpen($deal, $requirement->stage_id, ['exit' => $exit->label, 'closed_by' => 'staff', 'by' => $by->id]);
                ($this->take)($deal->offer, $exit, Actor::Manager, $by, ['for' => 'manager']);
            }
            $payment->delete();
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Чек от '.Ago::day($payment->created_at).' — оплата поставщику, а не '
                .($payment->invoice->seller->config()['name'] ?? 'по счёту').' ('.Money::rub($payment->amount).'): перенесли']);
        });
        Nav::forgetStaffCounts();
        $invoice->refreshLists();
    }

    public function toInvoice(Deal $deal, User $by): void
    {
        $invoice = self::invoiceTarget($deal) ?? throw ValidationException::withMessages(['payment_proof' => 'Перенести некуда: письмо страховой ушло или счёт оплачен']);
        $proof = $deal->sentPaymentProof();
        $files = $proof->getMedia('files');
        $offer = $deal->offer;
        $back = $offer->position(Track::Sale)->stage->exitsFor(Actor::Staff, $deal)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата не поступила'))
            ?? throw ValidationException::withMessages(['payment_proof' => 'У шага нет возврата на оплату']);
        // Чек ПРАЙМ — одним файлом: один PDF как есть, несколько — склеенные (`PaymentProofPdf`).
        $tmp = tempnam(sys_get_temp_dir(), 'proof-');
        $files->count() === 1 ? copy($files->first()->getPath(), $tmp) : file_put_contents($tmp, (string) PaymentProofPdf::render($deal));
        $name = $files->count() === 1 ? $files->first()->file_name : PaymentProofPdf::fileName($deal);
        try {
            DB::transaction(function () use ($offer, $back, $by, $files, $invoice, $tmp, $name) {
                ($this->take)($offer, $back, Actor::Staff, $by);
                $files->each(fn (Media $m) => $m->delete());
                ($this->claim)($invoice->fresh(), $by, round($invoice->remaining() - $invoice->claimed(), 2), null,
                    new UploadedFile($tmp, $name, 'application/pdf', null, true), null, PaymentSource::Bank);
                $offer->log(OfferEventType::Note, $by, ['text' => 'Платёжка поставщику — оплата '.($invoice->seller->config()['name'] ?? 'по счёту').': перенесли, ждём чек поставщику']);
            });
        } finally {
            is_file($tmp) && @unlink($tmp);
        }
        Nav::forgetStaffCounts();
    }

    /** Копия файла сообщения — в просьбу: медиатека переносит загруженное, а файл сообщения удалится вместе с ним. */
    private function attachCopy(Requirement $requirement, Media $slip): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'slip-');
        copy($slip->getPath(), $tmp);
        try {
            ($this->attach)($requirement, new UploadedFile($tmp, $slip->file_name, $slip->mime_type, null, true));
        } finally {
            is_file($tmp) && @unlink($tmp);
        }
    }
}
