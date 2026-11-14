<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidPayment;
use App\Billing\Payment;
use App\Billing\PaymentState;
use App\Garage\CarState;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\FastForward;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Actions\MarkSold;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Stage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Вернуть сделку в работу…» (14.11.2026, владелец: «нажмут случайно „Уже продана“ — и не вернуть никак»; 666916 — шаги
 * прокликали руками до «Поставщику оплачено», а Совкомбанку не платили): закрытая сделка снова идёт, на выбранном шаге
 * из тех, что она прошла. Машина — снова «Идёт сделка» (гаражная Совкомбанка — снова «продана»), «Поставщику оплачено»
 * снимается, если шаг оплаты поставщику снова впереди. Закрыла «Уже продана» (`MarkSoldDeal`, снимок `sold` в «Истории») —
 * откатывается и её: оплаты аннулируются, отметка договора и даты, вывоз, выдача на парковке, место ТС.
 */
final class ReopenDeal
{
    public function __construct(private EnterStage $enter, private VoidPayment $voidPayment, private MarkSold $markSold, private SetCarPlace $place) {}

    public static function allows(?Deal $deal, ?User $by = null): bool
    {
        if (! $deal || $deal->state !== DealState::Done || $deal->isAuction() || ($by && ! $by->canManageCrm())) {
            return false;
        }
        $offer = $deal->offer;

        return $offer?->state === OfferState::Delivered
            || ($offer?->state === OfferState::Garage && $deal->namesBuyer() && $deal->garageCar()->where('state', CarState::Settled)->exists());
    }

    /**
     * Шаги, что эта сделка прошла (без закрытия): [stage, at] в порядке маршрута. Перепрыгнутые «Уже продана» (`FastForward`
     * от шага, с которого прыгнули) — тоже: туда возвращают чаще всего (оплата поставщику, договор).
     */
    public static function stages(Deal $deal): Collection
    {
        $events = OfferEvent::where('offer_id', $deal->offer_id)->where('type', OfferEventType::StageEntered->value)
            ->where('created_at', '>=', $deal->created_at)->orderBy('id')->get();
        $at = $events->mapWithKeys(fn ($e) => [(int) ($e->payload['to_id'] ?? 0) => $e->created_at]);
        $ids = $at->keys()->filter()->all();
        $snapEvent = self::snapshotEvent($deal);
        if ($snapEvent && ($from = Stage::with('workflow')->find($snapEvent->payload['sold']['from'] ?? 0))) {
            foreach (FastForward::from($deal, $from, fn () => false)->passed as $skipped) {
                $at[$skipped->id] ??= $snapEvent->created_at;
                $ids[] = $skipped->id;
            }
        }
        $first = Stage::with('workflow')->find($ids[0] ?? 0);
        $order = $first ? $first->workflow->stages()->pluck('workflow_stages.id')->flip() : collect();

        return Stage::whereIn('id', array_unique($ids))->get()->reject(fn (Stage $s) => $s->offer_state === OfferState::Delivered)
            ->sortBy(fn (Stage $s) => $order[$s->id] ?? PHP_INT_MAX)
            ->map(fn (Stage $s) => ['stage' => $s, 'at' => $at[$s->id] ?? null])->values();
    }

    private static function snapshotEvent(Deal $deal): ?OfferEvent
    {
        return OfferEvent::where('offer_id', $deal->offer_id)->where('type', OfferEventType::Note->value)->whereNotNull('payload->sold')
            ->latest('id')->get()->first(fn ($e) => ($e->payload['sold']['deal'] ?? null) === $deal->id);
    }

    /** Снимок «Уже продана», что закрыла эту сделку. */
    public static function snapshot(Deal $deal): ?array
    {
        return self::snapshotEvent($deal)?->payload['sold'];
    }

    /** Шаг оплаты поставщику — на шаге возврата или впереди: «Поставщику оплачено» снимается. */
    public static function unpaysSupplier(Deal $deal, Stage $to): bool
    {
        return $to->paysOut() || FastForward::from($deal, $to->loadMissing('workflow'), fn () => false)->passesSupplierPayout();
    }

    public function __invoke(Deal $deal, Stage $to, User $by, bool $undoSold = true): Deal
    {
        return DB::transaction(function () use ($deal, $to, $by, $undoSold) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! self::allows($deal, $by)) {
                throw ValidationException::withMessages(['deal' => 'Вернуть в работу можно только закрытую сделку']);
            }
            if (! self::stages($deal)->contains(fn ($row) => $row['stage']->is($to))) {
                throw ValidationException::withMessages(['stage' => 'Этот шаг сделка не проходила']);
            }
            $offer = Offer::whereKey($deal->offer_id)->lockForUpdate()->firstOrFail();
            $snap = $undoSold ? self::snapshot($deal) : null;
            $dropped = [];

            $deal->forceFill(['state' => DealState::Active, 'closed_at' => null])->save();
            $from = $offer->state;
            $offer->forceFill(['state' => OfferState::Sold])->save();
            $offer->log(OfferEventType::StateChanged, $by, ['from' => $from->value, 'to' => OfferState::Sold->value, 'reopen' => true]);
            if ($car = $deal->garageCar()->where('state', CarState::Settled)->first()) {
                $car->moveTo(CarState::Sold, by: $by);
            }

            if ($deal->supplier_paid_at && self::unpaysSupplier($deal, $to)) {
                $dropped[] = 'поставщику оплачено '.$deal->supplier_paid_at->format('d.m');
                $deal->forceFill(['supplier_paid_at' => null])->save();
            }
            if ($snap) {
                $payments = Payment::whereIn('id', $snap['payments'] ?? [])->where('state', PaymentState::Confirmed)->get();
                foreach ($payments as $p) {
                    ($this->voidPayment)($p, $by, 'Сделка снова в работе');
                }
                $payments->isNotEmpty() && $dropped[] = 'оплаты «Уже продана»';
                $stamps = array_fill_keys(array_intersect($snap['stamps'] ?? [], ['supplier_paid_at', 'prepaid_at']), null);
                if (! empty($snap['contract'])) {
                    $stamps += ['contract_signed_at' => null, 'contract_signed_by' => null];
                    $dropped[] = 'договор подписан';
                }
                $stamps && $deal->forceFill($stamps)->save();
                if (($t = Transport::find($snap['transport']['id'] ?? 0)) && $t->state === TransportState::Cancelled && ! Transport::openFor($offer)) {
                    $t->update(['state' => $snap['transport']['state'], 'cancelled_at' => null, 'cancel_reason' => null]);
                    TransportMoved::dispatch($t, $by, 'reopened');
                    $dropped[] = 'вывоз снова идёт';
                }
                if (($v = Vehicle::find($snap['vehicle'] ?? 0)) && $v->state !== VehicleState::Released && $v->sold_at) {
                    $this->markSold->clear($v, $by, 'Сделка снова в работе');
                }
                if (($place = CarPlace::tryFrom((string) ($snap['place'] ?? ''))) && $offer->fresh()->car_place === CarPlace::Buyer) {
                    ($this->place)($offer->fresh(), $place, $by);
                }
            }
            if ($deal->paid_in_at && ! $deal->fresh()->fullyPaid() && $deal->schemeOf()->paysCar()) {
                $deal->forceFill(['paid_in_at' => null])->save();
            }

            ($this->enter)($offer->fresh(), $to, $by, ['label' => 'Сделка снова в работе'], back: true);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Сделка снова в работе: «'.$to->name.'»'.($dropped ? ', снято: '.implode(', ', $dropped) : '')]);

            return $deal->fresh();
        });
    }
}
