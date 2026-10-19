<?php

namespace App\Offers\Actions;

use App\Cars\Vin\RememberVin;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Transport;
use App\Mail\Actions\LinkThread;
use App\Media\Jobs\StampPhotos;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\DealAudience;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Sale;
use App\Support\Changes;
use App\Users\User;
use App\Vendors\Actions\LearnSenders;
use App\Vendors\Vendor;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\DeadlineSource;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;

final class UpdateOffer
{
    public function __construct(private StartRoute $startRoute, private UpdateDealCost $dealCost) {}

    /**
     * @param  array  $log  к записи «Изменён» в историю — откуда правка (`source: migtorg`, `why: «По СМС …»`)
     * @param  bool  $reprice  переоценка админом («Вернуть в продажу»): новая закупочная — и для новых подтверждений
     */
    public function __invoke(Offer $offer, array $data, ?User $by, array $log = [], bool $reprice = false): Offer
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
        // Новая закупочная у предложения с идущей сделкой — только закупочная поставщика (`UpdateDealCost`): цена
        // менеджера в сделке закреплена с его подтверждения (15.10.2026).
        $deal = $offer->isDirty('floor_price') && $offer->floor_price ? $offer->costDeal() : null;
        // Вышло в продажу — новая закупочная менеджерам не видна никак (15.10.2026): подтверждения закрепляют прежнюю
        // (`shown_floor`, `Offer::bidFloor`), от неё же заявленная, порог и скидки (`declaredPrice`). Переоценка админом —
        // новая цена для всех.
        if ($reprice) {
            $offer->shown_floor = null;
        } elseif ($offer->isDirty('floor_price') && $offer->getOriginal('floor_price') && $offer->getOriginal('state') !== OfferState::Draft) {
            $offer->shown_floor ??= (int) $offer->getOriginal('floor_price');
        }
        // Правка пишет и предложение, и его машину (`Cars\Car`): история и «что поменялось» — по обеим.
        $diff = Changes::ofLane($offer);
        $changed = [...array_keys($offer->getDirty()), ...$offer->carDirty()];
        $offer->save();
        if (array_intersect(['audience_rules', 'vendor_id'], $changed)) {
            app(SyncViewers::class)($offer);
            app(NotifyViewers::class)($offer, live: true);
        }
        // Вендора поставили руками письму, отправителя которого разбор не знал, — запомнить его адрес у вендора.
        if (in_array('vendor_id', $changed, true) && $offer->vendor_id) {
            app(LearnSenders::class)($offer);
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
            // Было → стало (`Changes`): ключ номера убытка — производная колонка, в истории это тот же «номер убытка».
            $offer->log(OfferEventType::Updated, $by, $diff + $log);
            if ($deal) {
                ($this->dealCost)($deal->setRelation('offer', $offer), (int) $offer->floor_price, $by);
            }
            (new RememberVin)($offer);
        }
        self::identityChanged($offer, $changed);
        // Гараж запретили — ждущие «В гараж» отклоняются (решение владельца 03.10.2026), принять их уже нельзя.
        if (in_array('garage_allowed', $changed, true) && ! $offer->garage_allowed) {
            $offer->bids()->where('state', BidState::Active)->where('kind', BidKind::Garage)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));
        }
        if (in_array('share_locked', $changed, true)) {
            StampPhotos::dispatch($offer->carRecord());
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
        if (in_array('bids_close_at', $changed, true)) {
            $offer = self::bidsCloseChanged($offer, $by);
        }
        if ($offer->vendor_id) {
            $offer = ($this->startRoute)($offer->load('vendor.workflows'), $by);
        }
        // Вендор, чьи машины вывозим все (Совкомбанк, 17.10.2026): встал вендор — встаёт и задача на вывоз в логистике.
        // Один раз на машину: отменённый вывоз сам не возвращается. Машина из «Таблицы Кати» — сделка вне системы, её вывоз
        // заводят руками («Вывезти» в CRM), а не сам (18.10.2026: 93 такие висели «Без исполнителя»).
        if (in_array('vendor_id', $changed, true) && $offer->vendor?->pickup_on_arrival && $offer->car_id && ! OpenPickup::blocker($offer)
            && ! LegacyClaim::listed($offer)
            && ! Transport::where('car_id', $offer->car_id)->where('origin', 'sale')->exists()) {
            app(OpenPickup::class)($offer, $by);
        }

        return $offer;
    }

    /**
     * Срок приёма поменяли у открытого (17.10.2026, начальник: продлили показ — а машина висит в «Приём закрыт», надо
     * обновлять страницу и жать «Возобновить приём»). Этап «Приём подтверждений» берёт новый срок часами; приём уже
     * закрылся и маршрут ушёл в «Выбор подтверждения» — продление само проходит его выход обратно в приём.
     */
    private static function bidsCloseChanged(Offer $offer, ?User $by): Offer
    {
        $position = $offer->state === OfferState::Open ? $offer->position(Track::Sale)?->load('stage.exits.to') : null;
        if (! $position?->stage) {
            return $offer;
        }
        if ($position->stage->deadline_source === DeadlineSource::BidsClose) {
            $position->update(['deadline_at' => $offer->bids_close_at, 'reminded_at' => null, 'overdue_at' => null]);

            return $offer;
        }
        $back = $offer->bidsOpen() ? $position->stage->exitsFor(Actor::Staff)->first(fn (Outcome $e) => $e->to?->deadline_source === DeadlineSource::BidsClose) : null;

        return $back ? app(TakeExit::class)($offer, $back, Actor::Staff, $by) : $offer;
    }

    /**
     * Номер убытка или VIN машины поменяли — из CRM или с парковки (`UpdateVehicle`: поле у машины одно): письма о той же
     * ТС, что уже пришли и ни к чему не привязаны, едут к продаже; номер нашёлся среди лотов Мигторга — поля и фото оттуда
     * сразу, не дожидаясь синхронизации.
     */
    public static function identityChanged(Offer $offer, array $changed): void
    {
        if (array_intersect(['claim_ref', 'vin'], $changed)) {
            app(LinkThread::class)->forOffer($offer);
        }
        if (in_array('claim_ref', $changed, true)) {
            // Лоты прежнего номера больше не этого предложения — свободны для своего.
            DB::table('migtorg_lots')->where('offer_id', $offer->id)->where(fn ($q) => $q->whereNull('claim_ref_key')->orWhere('claim_ref_key', '!=', (string) $offer->claim_ref_key))->update(['offer_id' => null]);
            ImportMigtorgLot::auto($offer);
        }
    }
}
