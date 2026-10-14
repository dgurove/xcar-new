<?php

namespace App\Billing\Acquiring;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Offers\Deal;
use App\Offers\Offer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Открытая ссылка, которую админ мог не заметить и начать вторую (09.10.2026, владелец: «писал бы, что ссылка уже есть,
 * и перекидывал туда»): по той же машине — её сделки (`deal_id`), засчитанная в сделку (`credit_deal_id`) или ручная с
 * этим предложением; иначе ручная тому же плательщику — контрагентом или именем без учёта регистра, «ё» и двойных
 * пробелов (Морковину за час взяли три). Одна дверь для формы (до отправки) и для `IssueServiceInvoice` (сторож).
 */
final class ExistingLink
{
    public static function find(?Offer $offer, ?int $partyId = null, ?string $name = null): ?PayLink
    {
        return ($offer ? self::forOffer($offer) : null) ?? self::forPayer($partyId, $name);
    }

    public static function forOffer(Offer $offer): ?PayLink
    {
        $deals = Deal::where('offer_id', $offer->id)->select('id');

        return self::open(fn (Builder $i) => $i->where(fn ($w) => $w->where('offer_id', $offer->id)->orWhereIn('deal_id', $deals)->orWhereIn('credit_deal_id', $deals)));
    }

    public static function forPayer(?int $partyId, ?string $name): ?PayLink
    {
        $name = self::normal((string) $name);
        if (! $partyId && $name === '') {
            return null;
        }
        $norm = "translate(lower(regexp_replace(btrim(%s), '\\s+', ' ', 'g')), 'ё', 'е')";

        return self::open(fn (Builder $i) => $i->where('kind', ChargeKind::Service->value)->whereNull('vehicle_id')
            ->where(fn ($w) => $w->when($partyId, fn ($x) => $x->orWhere('party_id', $partyId))
                ->when($name !== '', fn ($x) => $x->orWhereIn('party_id', fn ($p) => $p->select('id')->from('billing_parties')->whereRaw(sprintf($norm, 'name').' = ?', [$name]))
                    ->orWhereIn('id', PayLink::where('state', PayLinkState::Open)->whereRaw(sprintf($norm, 'payer_name').' = ?', [$name])->select('invoice_id')))));
    }

    public static function normal(string $name): string
    {
        return str_replace('ё', 'е', mb_strtolower(PayLink::squash($name)));
    }

    private static function open(\Closure $invoices): ?PayLink
    {
        return PayLink::where('state', PayLinkState::Open)
            ->whereIn('invoice_id', Invoice::where('direction', 'issued')->where($invoices)->select('id'))
            ->with(['invoice.party', 'invoice.offer', 'invoice.deal.offer'])
            ->latest('id')->first();
    }
}
