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

    /** @param  bool  $force  искать и там, где всё уже заполнено (шторка «Собственник»: «Из договора комиссии») */
    public function __invoke(Deal $deal, bool $apply = true, bool $force = false): ?array
    {
        if (! $deal->isLive() || ! $deal->hasContract() || $deal->seller() !== Seller::Prime || $deal->isDkp()) {
            return null;
        }
        $contract = DealContract::for($deal);
        // Платёж поставщику (сумма, реквизиты, назначение) дозаполняется и там, где собственник уже есть (14.11.2026).
        if (! $force && $contract->seller_party_id && $contract->supplier_price !== null) {
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
            $party = self::partyFor($deal, $found);
            $fill['seller_party_id'] = $party->id;
            $deal->offer->log(OfferEventType::Note, null, ['text' => 'ДКП: собственник из договора комиссии — '.$party->name]);
        }
        $contract->forceFill($fill)->save();
        ($this->settle)($contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));

        return $found;
    }

    /**
     * Сторона собственника из разобранного: сам поставщик (тот же ИНН, что у его реквизитов) — его сторона, одна на все сделки
     * (25.11.2026: у 40 сделок было 40 копий «Совкомбанк страхование»), иначе новая.
     *
     * @param  array{kind: PartyKind, party: array<string, mixed>}  $found
     */
    public static function partyFor(Deal $deal, array $found): Party
    {
        $vendor = $deal->offer?->vendor?->party;
        $inn = $found['party']['inn'] ?? null;
        if ($vendor && $inn && $vendor->inn === $inn && $found['kind'] === PartyKind::Company) {
            return $vendor;
        }

        return Party::create(['kind' => $found['kind']] + $found['party']);
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
