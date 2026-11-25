<?php

namespace App\Offers\Actions;

use App\Billing\Actions\StorePassportScan;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Offers\OwnerDocs;
use App\Support\Ago;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Продавец ДКП собственника — из паспорта в письме страховой (25.11.2026, владелец: Т-Страхование шлёт паспорт и СТС собственника,
 * «распознавать и сразу заполнять им продавца в ДКП»). Сделка по ДКП собственника (`Deal::isDkp`: Т-Страхование, ИНСАЙТ, ПСБ),
 * среди файлов машины — паспорт (`OwnerDocs`), у продавца (`deal_contracts.seller_party_id`) нет ни паспорта, ни скана: продавец —
 * физлицо с ФИО из карточки или письма, файлы паспорта последнего письма с ним — сканом продавца (`StorePassportScan::fromMedia`),
 * читает `ReadPassport` (только пустые поля, вписанное руками не трогает). Не распознался — скан у продавца, «Распознать заново»
 * и поля — в «Данных договора».
 * Зовут: конец чтения документов машины (`Cars\Sources\Collect`), принятие сделки (`Give`), разово — `deals:owner-passports`.
 */
final class FillOwnerFromPassport
{
    public function __construct(private StorePassportScan $store) {}

    /** @return list<Media>|null какие файлы встали сканом (без `$apply` — встали бы); null — нечего делать */
    public function __invoke(Deal $deal, bool $apply = true): ?array
    {
        if (! $deal->isLive() || ! $deal->isDkp() || ! $deal->hasContract()) {
            return null;
        }
        $contract = DealContract::for($deal);
        $seller = $contract->seller;
        if ($seller && (! $seller->isPerson() || $seller->hasPassport() || $seller->getMedia('passport')->isNotEmpty())) {
            return null;
        }
        $passports = OwnerDocs::among($deal->offer->getMedia('papers'), OwnerDocs::PASSPORT)->sortBy('id')->values();
        if ($passports->isEmpty()) {
            return null;
        }
        // Паспорт последнего письма с ним — страницы одного человека; без письма — два последних файла.
        $letter = $passports->last()->getCustomProperty('letter');
        $files = $letter ? $passports->filter(fn (Media $m) => $m->getCustomProperty('letter') === $letter)->values() : $passports->take(-2)->values();
        if (! $apply) {
            return $files->all();
        }
        $message = $letter ? Message::find($letter) : null;
        $name = $deal->offer->insured_name ?: $message?->field('insured_name');
        try {
            DB::transaction(function () use ($deal, $contract, $seller, $files, $name, $message) {
                Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
                if (! $seller) {
                    $seller = Party::create(['kind' => PartyKind::Person, 'name' => (string) $name]);
                    $contract->forceFill(['seller_party_id' => $seller->id])->save();
                } elseif (blank($seller->name) && filled($name)) {
                    $seller->update(['name' => $name]);
                }
                $this->store->fromMedia($seller, $files);
                $deal->offer->log(OfferEventType::Note, null, ['text' => 'ДКП: продавец из паспорта'.($message ? ' в письме '.Ago::day($message->created_at) : '')]);
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $files->all();
    }
}
