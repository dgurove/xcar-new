<?php

namespace App\Offers\Actions;

use App\Billing\Actions\SaveBuyerData;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Собственник (комитент) по договору комиссии ПРАЙМ — шторка «Собственник» в «Продаже» (25.11.2026, владелец: «может быть
 * физлицо, юрлицо, ИП»; «иногда да, Совком, иногда нет, иногда неправильно распознаётся — чтобы красиво и удобно указывалось
 * и менялось в CRM»). Пути:
 * - `vendor` — сам поставщик: его сторона (`Party::forVendor`), одна на все сделки;
 * - `found` — распознанное из «Договора комиссии» в письмах (`FillCommissionOwner` без записи), новой стороной;
 * - `edit` — поправить нынешнего полями по виду (`SaveBuyerData`); общую сторону поставщика не правим — заводим свою;
 * - `new` — другой собственник полями.
 * Договор — заново (`SettleContract`), в «Историю» — кто стал собственником.
 */
final class SetDealOwner
{
    public const MODES = ['vendor', 'found', 'edit', 'new'];

    public function __construct(private SaveBuyerData $save, private SettleContract $settle, private FillCommissionOwner $fill) {}

    /** Шторка есть: ПРАЙМ продаёт по договору комиссии (не ДКП собственника), сделка живая. */
    public static function allows(Deal $deal): bool
    {
        return $deal->isLive() && $deal->hasContract() && ! $deal->isDkp() && DealContract::for($deal)->commissioned();
    }

    /** Сторона поставщика — если у него есть реквизиты (ИНН); иначе выбора «сам поставщик» нет. */
    public static function vendorParty(Deal $deal): ?Party
    {
        $party = $deal->offer?->vendor?->party;

        return $party && filled($party->inn) ? $party : null;
    }

    public function __invoke(Deal $deal, User $by, string $mode, array $data = []): Party
    {
        return DB::transaction(function () use ($deal, $by, $mode, $data) {
            Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $contract = DealContract::for($deal);
            $current = $contract->seller;
            $vendor = self::vendorParty($deal);
            $party = match ($mode) {
                'vendor' => $vendor ?? throw ValidationException::withMessages(['owner' => 'У поставщика нет реквизитов']),
                'found' => $this->found($deal),
                'edit' => ($this->save)($current && $current->id !== $vendor?->id && ! $current->is_self ? $current : new Party, $data['owner'] ?? []),
                default => ($this->save)(new Party, $data['owner'] ?? []),
            };
            if (array_key_exists('commission_no', $data)) {
                $contract->commission_no = filled($data['commission_no']) ? trim($data['commission_no']) : null;
            }
            $changed = $contract->seller_party_id !== $party->id || $mode === 'edit';
            $contract->seller_party_id = $party->id;
            $contract->save();
            if ($changed) {
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Собственник по договору комиссии: '.self::label($party)]);
            }
            ($this->settle)($contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));

            return $party;
        });
    }

    /** Имя с видом: «ИП Токарев Иван Николаевич», «ООО «ИТЕКО Россия»», «Иванов Иван Иванович». */
    public static function label(Party $party): string
    {
        return ($party->kind === PartyKind::Entrepreneur ? 'ИП ' : '').$party->name;
    }

    private function found(Deal $deal): Party
    {
        $found = ($this->fill)($deal, apply: false, force: true);
        if (! $found) {
            throw ValidationException::withMessages(['owner' => 'В письмах нет договора комиссии, который удалось разобрать']);
        }

        return FillCommissionOwner::partyFor($deal, $found);
    }
}
