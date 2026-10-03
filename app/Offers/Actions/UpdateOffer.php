<?php

namespace App\Offers\Actions;

use App\Cars\Vin\RememberVin;
use App\Mail\Actions\LinkThread;
use App\Media\Jobs\StampPhotos;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\StartRoute;
use App\Workflow\DeadlineSource;
use App\Workflow\Position;

final class UpdateOffer
{
    public function __construct(private StartRoute $startRoute) {}

    public function __invoke(Offer $offer, array $data, User $by): Offer
    {
        if (array_key_exists('vin', $data)) {
            $data['vin'] = $data['vin'] ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['vin'])) : null;
        }
        $offer->fill($data);
        // Сменили вендора, а НДС не прислали (у модератора галки нет) — НДС цен от вендора. Присланный — письмом или
        // галкой админа — не перетирается, даже если совпадает с прежним.
        if ($offer->isDirty('vendor_id') && ! array_key_exists('prices_include_vat', $data) && $offer->vendor_id) {
            $offer->prices_include_vat = Vendor::offersVat($offer->vendor_id);
        }
        $changed = array_keys($offer->getDirty());
        $offer->save();
        if (array_intersect(['audience_rules', 'vendor_id'], $changed)) {
            app(SyncViewers::class)($offer);
            app(NotifyViewers::class)($offer, live: true);
        }
        if ($changed) {
            // Ключ номера убытка — производная колонка, в истории это тот же «номер убытка».
            $offer->log(OfferEventType::Updated, $by, ['fields' => array_values(array_diff($changed, ['claim_ref_key']))]);
            (new RememberVin)($offer);
        }
        // Номер убытка или VIN вписали руками — письма о той же ТС, что уже пришли и ни к чему не привязаны, едут к ней.
        if (array_intersect(['claim_ref', 'vin'], $changed)) {
            app(LinkThread::class)->forOffer($offer);
        }
        // Гараж запретили — ждущие «В гараж» отклоняются (решение владельца 03.10.2026), принять их уже нельзя.
        if (in_array('garage_allowed', $changed, true) && ! $offer->garage_allowed) {
            $offer->bids()->where('state', BidState::Active)->where('kind', BidKind::Garage)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));
        }
        if (in_array('share_locked', $changed, true)) {
            StampPhotos::dispatch($offer);
        }
        if (in_array('insurer_deadline_at', $changed, true)) {
            // Срок от страховой могли вписать после того, как оффер встал на этап.
            Position::where('offer_id', $offer->id)->whereHas('stage', fn ($s) => $s->where('deadline_source', DeadlineSource::InsurerDeadline))
                ->update(['deadline_at' => $offer->insurer_deadline_at?->copy()->endOfDay(), 'reminded_at' => null, 'overdue_at' => null]);
        }
        if ($offer->vendor_id) {
            $offer = ($this->startRoute)($offer->load('vendor.workflows'), $by);
        }

        return $offer;
    }
}
