<?php

namespace App\Offers\Actions;

use App\Cars\Vin\RememberVin;
use App\Mail\Actions\LinkThread;
use App\Media\Jobs\StampPhotos;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\DealAudience;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Sale;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\StartRoute;
use App\Workflow\DeadlineSource;
use App\Workflow\Position;
use Illuminate\Support\Facades\DB;

final class UpdateOffer
{
    public function __construct(private StartRoute $startRoute) {}

    /** @param array $log к записи «Изменён» в историю — откуда правка (`source: migtorg`) */
    public function __invoke(Offer $offer, array $data, ?User $by, array $log = []): Offer
    {
        $was = $offer->value;
        // Показ сделки кончился, а его продлили — список снова видит её: уведомление всем, а не только новым.
        $showWasOver = $offer->deal_show_until !== null && $offer->dealShowOver();
        $offer->fill($data);
        // Сменили оценочную, закупочную не трогали — закупочная от неё же, если не вписана рукой (`Sale::floorFor`).
        if ($offer->isDirty('value') && ! $offer->isDirty('floor_price') && ($floor = Sale::floorFor($offer->floor_price, $was, $offer->value))) {
            $offer->floor_price = $floor;
        }
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
        // Белый список «Кому показывать в сделке»: вновь добавленным — уведомление, как о новом.
        $reopened = $showWasOver && in_array('deal_show_until', $changed, true) && ! $offer->dealShowOver();
        if (array_key_exists('deal_audience_users', $data) || array_key_exists('deal_audience_groups', $data)) {
            $sync = DealAudience::sync($offer,
                (array) ($data['deal_audience_users'] ?? []),
                (array) ($data['deal_audience_groups'] ?? []));
            if ($sync['changed']) {
                $offer->log(OfferEventType::Updated, $by, ['fields' => ['deal_audience']] + $log);
            }
            $notify = $reopened ? DealAudience::managerIds($offer) : $sync['added'];
            if ($notify && ! $offer->dealShowOver()) {
                app(NotifyDealAudience::class)->added($offer->refresh(), $notify);
            }
        } elseif ($reopened) {
            app(NotifyDealAudience::class)->added($offer->refresh(), DealAudience::managerIds($offer));
        }
        if ($changed) {
            // Ключ номера убытка — производная колонка, в истории это тот же «номер убытка».
            $offer->log(OfferEventType::Updated, $by, ['fields' => array_values(array_diff($changed, ['claim_ref_key']))] + $log);
            (new RememberVin)($offer);
            // Машина одна: тождество связанной ТС парковки правится там же, предложение — её зеркало.
            Sale::toVehicle($offer, $changed, $by);
        }
        // Номер убытка или VIN вписали руками — письма о той же ТС, что уже пришли и ни к чему не привязаны, едут к ней.
        if (array_intersect(['claim_ref', 'vin'], $changed)) {
            app(LinkThread::class)->forOffer($offer);
        }
        // Номер убытка нашёлся среди лотов Мигторга — поля и фото оттуда сразу, не дожидаясь синхронизации.
        if (in_array('claim_ref', $changed, true)) {
            // Лоты прежнего номера больше не этого предложения — свободны для своего.
            DB::table('migtorg_lots')->where('offer_id', $offer->id)->where(fn ($q) => $q->whereNull('claim_ref_key')->orWhere('claim_ref_key', '!=', (string) $offer->claim_ref_key))->update(['offer_id' => null]);
            ImportMigtorgLot::auto($offer);
        }
        // Гараж запретили — ждущие «В гараж» отклоняются (решение владельца 03.10.2026), принять их уже нельзя.
        if (in_array('garage_allowed', $changed, true) && ! $offer->garage_allowed) {
            $offer->bids()->where('state', BidState::Active)->where('kind', BidKind::Garage)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));
        }
        if (in_array('share_locked', $changed, true)) {
            // Запрет шеринга переключил админ — он его и держит: оценка менеджером при закрытии его не снимет.
            if ($offer->share_locked_by_estimate) {
                $offer->forceFill(['share_locked_by_estimate' => false])->saveQuietly();
            }
            StampPhotos::dispatch($offer);
        }
        // Цена продажи поставлена (своя админа, «Взять», «Из текста») — оценка менеджеров больше не нужна.
        if (in_array('asking_price', $changed, true) && $offer->asking_price) {
            app(CloseEstimates::class)($offer, $by);
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
