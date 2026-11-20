<?php

namespace App\Offers\Console;

use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\Vat;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\SupplierVat;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * НДС поставщика прошлым машинам (20.11.2026, `Offers\SupplierVat`): письма поставщика и прочитанные вложения (кеш текста, OCR
 * не запускается) машин в продаже и в идущих сделках — самое свежее, где сказан НДС. Без `--apply` — только список: машина,
 * ставка, откуда и кусок текста, какой счёт за ТС перевыставится. Вписанное руками не трогается.
 */
final class SupplierVatCommand extends Command
{
    protected $signature = 'offers:supplier-vat {--apply}';

    protected $description = 'НДС поставщика из писем и документов: список, с --apply — записать';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        // Черновики — нет: НДС нужен счёту за ТС, а их письма разберёт `OnMessage` по ходу. По одной машине — память не копится.
        $ids = Offer::query()->where(fn ($q) => $q->whereIn('state', [OfferState::Gallery, OfferState::Open])
            ->orWhereIn('id', Deal::query()->active()->select('offer_id')))
            ->where(fn ($q) => $q->whereNull('supplier_vat_source')->orWhere('supplier_vat_source', '!=', 'human'))
            ->orderBy('id')->pluck('id');
        $rows = [];
        foreach ($ids as $id) {
            $offer = Offer::with('vendor')->find($id);
            $found = $this->letter($offer);
            $doc = $offer->carRecord() ? SupplierVat::fromDocuments($offer->carRecord()) : null;
            if ($doc && (! $found || $doc['at']->gt($found['at']))) {
                $found = $doc + ['source' => 'document'];
            }
            if (! $found || ($offer->supplier_vat === $found['rate'] && $offer->supplier_vat_source === $found['source'])) {
                continue;
            }
            $deal = Deal::where('offer_id', $offer->id)->active()->latest('id')->with('contract')->first();
            $invoice = $deal?->isPrime() && $deal->contract?->vat_rate === null
                ? $deal->issuedInvoices()->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->latest('id')->first() : null;
            $reissue = $invoice && (int) $invoice->vat_rate !== $found['rate'] ? ($invoice->state === InvoiceState::Paid ? 'оплачен '.Vat::label($invoice->vat_rate) : 'перевыставится с '.Vat::label($invoice->vat_rate)) : '';
            $rows[] = [$offer->number, $offer->vendor?->name, $offer->supplier_vat === null ? '—' : Vat::label($offer->supplier_vat), Vat::label($found['rate']),
                ($found['source'] === 'letter' ? 'письмо ' : 'документ ').$found['at']->format('d.m'), Str::limit($found['snippet'] ?? '', 70), $reissue];
            if ($apply) {
                SupplierVat::put($offer, $found['rate'], $found['source'], $found['message_id'], $found['at']);
            }
        }
        // Ставки нет нигде — умолчание вендора (грузовым своё, `supplier_vat_heavy`): черновики, галерея, продажа. Идущие сделки —
        // только списком: схему денег на ходу не меняем.
        $deals = [];
        foreach (Offer::query()->whereNull('supplier_vat')->whereNotNull('vendor_id')->orderBy('id')->pluck('id') as $id) {
            $offer = Offer::with('vendor')->find($id);
            $rate = \App\Vendors\Vendor::supplierVat($offer->vendor_id, $offer->isHeavy());
            if ($rate === null) {
                continue;
            }
            $deal = Deal::where('offer_id', $offer->id)->active()->latest('id')->first();
            if ($deal) {
                $deals[] = [$offer->number, $offer->vendor?->name, $offer->title(), Vat::label($rate), $deal->schemeOf()->label()];

                continue;
            }
            if (! in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true)) {
                continue;
            }
            $rows[] = [$offer->number, $offer->vendor?->name, '—', Vat::label($rate), 'вендор', Str::limit($offer->title(), 70), ''];
            if ($apply) {
                app(\App\Offers\Actions\UpdateOffer::class)($offer, ['supplier_vat' => $rate, 'supplier_vat_source' => 'vendor', 'supplier_vat_at' => now()], null, ['why' => 'НДС поставщика по вендору']);
            }
        }
        $this->table(['№', 'Вендор', 'Было', 'Стало', 'Откуда', 'Кусок', 'Счёт за ТС'], $rows);
        if ($deals) {
            $this->line('Идущие сделки без ставки — не трогаю, решать руками:');
            $this->table(['№', 'Вендор', 'ТС', 'По вендору', 'Схема'], $deals);
        }
        $this->info(count($rows).($apply ? ' записано' : ' найдено, ничего не записано (--apply — записать)'));

        return self::SUCCESS;
    }

    /** @return array{rate: int, at: \Illuminate\Support\Carbon, message_id: int, source: string, snippet: string}|null */
    private function letter(Offer $offer): ?array
    {
        $threads = Thread::where('offer_id', $offer->id)->pluck('id');
        if ($threads->isEmpty()) {
            return null;
        }
        $letters = Message::whereIn('thread_id', $threads)->where('direction', Direction::In)
            ->where(fn ($q) => $q->where('text_body', 'ilike', '%НДС%')->orWhere('html_body', 'ilike', '%НДС%'))
            ->orderByDesc('date_at')->orderByDesc('id')->limit(20)->get();
        foreach ($letters as $m) {
            if (($rate = SupplierVat::fromLetter($m)) !== null) {
                $text = (string) preg_replace('/\s+/u', ' ', $m->ownText());
                $at = mb_stripos($text, 'НДС');

                return ['rate' => $rate, 'at' => $m->date_at ?? $m->created_at, 'message_id' => $m->id, 'source' => 'letter',
                    'snippet' => $at === false ? '' : mb_substr($text, max(0, $at - 30), 70)];
            }
        }

        return null;
    }
}
