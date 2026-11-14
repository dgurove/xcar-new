<?php

namespace App\Offers\Actions;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Seller;
use App\Mail\Extraction\CommitentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\OfferSubject;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use Throwable;

/**
 * Собственник для ДКП ПРАЙМ сам из договора комиссии (14.11.2026, владелец: «сам из договора комиссии»): у сделки ПРАЙМ без
 * собственника — файлы предложения и писем по нему (`OfferSubject::files`, текстовым слоем без OCR), первый «Договор
 * комиссии…», где разобрался комитент (`CommitentFields`; с номером убытка — раньше остальных) — физлицо или организация
 * в `seller_party_id`, номер договора — в `commission_no`; что и куда платим поставщику — назначенная цена, назначение платежа и
 * реквизиты (`supplier_*`, блок «Совкомбанку по договору комиссии» в «Деньгах»). Вписанное руками не трогает. Договор собрался
 * — `SettleContract`.
 * Зовут: принятие сделки (`Give`), письмо по сделке (`OnInsurerReply`), разово — `deals:commission-owners`.
 *
 * @return array{number: ?string, kind: PartyKind, party: array<string, mixed>}|null что нашлось (без `$apply` — не пишет)
 */
final class FillCommissionOwner
{
    public function __construct(private SettleContract $settle) {}

    public function __invoke(Deal $deal, bool $apply = true): ?array
    {
        if (! $deal->isLive() || ! $deal->hasContract() || $deal->seller() !== Seller::Prime || $deal->isDkp()) {
            return null;
        }
        $contract = DealContract::for($deal);
        // Платёж поставщику (сумма, реквизиты, назначение) дозаполняется и там, где собственник уже есть (14.11.2026).
        if ($contract->seller_party_id && $contract->supplier_price !== null) {
            return null;
        }
        $found = $this->find($deal);
        if (! $found || ! $apply) {
            return $found;
        }
        $pay = $found['payment'] ?? [];
        $fill = ['commission_no' => $contract->commission_no ?: $found['number'],
            'supplier_price' => $contract->supplier_price ?? ($pay['price'] ?? null),
            'supplier_purpose' => $contract->supplier_purpose ?: ($pay['purpose'] ?? null),
            'supplier_requisites' => $contract->supplier_requisites ?: (($pay['payee'] ?? []) ?: null)];
        if (! $contract->seller_party_id) {
            $party = Party::create(['kind' => $found['kind']] + $found['party']);
            $fill['seller_party_id'] = $party->id;
            $deal->offer->log(OfferEventType::Note, null, ['text' => 'ДКП: собственник из договора комиссии — '.$party->name]);
        }
        $contract->forceFill($fill)->save();
        ($this->settle)($contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));

        return $found;
    }

    /** @return array{number: ?string, kind: PartyKind, party: array<string, mixed>}|null */
    private function find(Deal $deal): ?array
    {
        $ref = preg_replace('/\D+/', '', (string) $deal->offer->claim_ref);
        $best = null;
        try {
            foreach ((new OfferSubject($deal->offer))->files() as $file) {
                if (! preg_match('/договор[\W_]*комисс/iu', $file->scanName())) {
                    continue;
                }
                $text = DocumentText::layer($file);
                $found = $text ? CommitentFields::extract($text) : null;
                if (! $found || ! filled($found['party']['passport'] ?? $found['party']['inn'] ?? null)) {
                    continue;
                }
                if ($ref !== '' && preg_replace('/\D+/', '', (string) $found['number']) === $ref) {
                    return $found;
                }
                $best ??= $found;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $best;
    }
}
