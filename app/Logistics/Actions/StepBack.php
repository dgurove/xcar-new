<?php

namespace App\Logistics\Actions;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Channel;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Handover;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Actions\StepBack as RouteStepBack;
use App\Workflow\Track;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отменить последний шаг вывоза (18.10.2026, владелец: «хотели поменять исполнителя, а после назначения не поменять —
 * отменили вывоз и завели новый; надо, чтобы шаги можно было отменять»). По одному, с конца: «Привёз» → «Забрал» →
 * документы → созвон → исполнитель → логист. Отменяет сделавший шаг, логист задачи и ведущие логистику; «Привёз» —
 * только админ (он двигает место машины и гараж), документы — тот, кто их отправляет. Менеджер сделки, что забирает сам,
 * отменяет шагом своей сделки, не здесь.
 *
 * Что за шагом тянулось — назад: место машины («у владельца», «в пути»), подготовка в гараже (если расходов ещё нет),
 * шаг продажи, который «Забрал» / «Привёз» провели сами (`SyncHandover`) — откатом маршрута. Ключи и кадры остаются.
 */
final class StepBack
{
    public function __construct(private AssignExecutor $assign, private SetCarPlace $place, private RouteStepBack $routeBack) {}

    /** Последний сделанный шаг, что можно отменить (ключ `TransportEvent::STEPS`), — одно место на вид и действие. */
    public static function last(Transport $t): ?string
    {
        // Забрал аукцион — откат только «Отменить продажу на аукционе» (`UndoAuctionSale`).
        if ($t->channel === Channel::Auction) {
            return null;
        }
        // Забирает менеджер сделки — снять его, пока не забрал (19.10.2026); «Забрал» он откатывает в своей сделке.
        if ($t->channel === Channel::Manager) {
            return in_array($t->state, [TransportState::Assigned, TransportState::Agreed], true) ? 'executor' : null;
        }

        return match ($t->state) {
            // Перевод парковки привезли — ТС уже переставлена, назад не крутим; отрезок вывоза со стоянки — можно.
            TransportState::Delivered => $t->isPickup() || $t->from_depot_id ? 'delivered' : null,
            TransportState::Moving => 'picked',
            TransportState::Assigned, TransportState::Agreed => self::latest($t) ?? ($t->executor_id ? 'executor' : null),
            TransportState::Logist => 'logist',
            default => null,
        };
    }

    /** Может ли человек отменить шаг. */
    public static function allowed(Transport $t, User $by, string $step): bool
    {
        return match ($step) {
            'delivered' => $t->isOrder() ? $t->hasLogist($by) : $by->isAdmin(),
            'docs_sent' => $by->sendsLogisticsDocs(),
            'logist' => $by->leadsLogistics() && ! $t->isOrder(),
            'executor' => $t->channel === Channel::Logist ? $t->hasLogist($by) || ($by->leadsLogistics() && ! $t->isOrder()) : $by->leadsLogistics(),
            default => $t->isHandledBy($by),
        };
    }

    public function __invoke(Transport $t, User $by): Transport
    {
        $since = null;
        $step = DB::transaction(function () use ($t, $by, &$since) {
            $t = Transport::whereKey($t->id)->lockForUpdate()->firstOrFail();
            $step = self::last($t);
            if (! $step) {
                throw ValidationException::withMessages(['transport' => 'Отменять нечего']);
            }
            if (! self::allowed($t, $by, $step)) {
                throw ValidationException::withMessages(['transport' => $step === 'delivered' ? '«Привёз» отменяет админ' : 'Этот шаг отменить не можете']);
            }
            $since = $t->picked_at;
            match ($step) {
                'delivered' => $this->undeliver($t, $by),
                'picked' => $this->unpick($t, $by),
                'docs_received' => $t->update(['docs_received_at' => null]),
                'docs_sent' => $t->update(['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]),
                'called' => $t->update(['agreed_at' => null, 'pickup_at' => null, 'pickup_place' => null, 'state' => TransportState::Assigned]),
                'executor' => $this->unassign($t, $by),
                'logist' => $this->assign->release($t, $by),
            };

            return $step;
        });
        $t->refresh();
        // Исполнителя и логиста снимают своим событием (история «Исполнитель снят»), остальное — откатом шага.
        if (! in_array($step, ['executor', 'logist'], true)) {
            TransportMoved::dispatch($t, $by, 'undo:'.$step);
        }
        if (in_array($step, ['picked', 'delivered'], true) && $since) {
            $this->unpressSale($t, $since, $by);
        }

        return $t;
    }

    /** Самый поздний из шагов созвона и документов. */
    private static function latest(Transport $t): ?string
    {
        $times = array_filter(['called' => $t->agreed_at, 'docs_sent' => $t->docs_sent_at, 'docs_received' => $t->docs_received_at]);
        if (! $times) {
            return null;
        }
        uasort($times, fn (Carbon $a, Carbon $b) => $b <=> $a);

        return array_key_first($times);
    }

    private function unpick(Transport $t, User $by): void
    {
        $t->update(['state' => $t->agreed_at ? TransportState::Agreed : TransportState::Assigned, 'picked_at' => null, 'delivered_at' => null]);
        if ($offer = $t->saleOffer()) {
            ($this->place)($offer, CarPlace::Owner, $by);
        }
    }

    private function undeliver(Transport $t, User $by): void
    {
        // Привезли на стоянку логиста — следующий отрезок, пока по нему ничего не сделали, уходит вместе с «Привёз».
        if ($t->destination === Point::Depot && ($next = $t->nextLeg)) {
            if ($next->executor_id || ! in_array($next->state, [TransportState::New, TransportState::Logist], true)) {
                throw ValidationException::withMessages(['transport' => 'Следующий отрезок уже идёт: «Привёз» на стоянку не отменить']);
            }
            $next->delete();
        }
        $offer = $t->saleOffer();
        $car = $offer?->garageCar()->first();
        if ($car && $car->state !== CarState::Waiting) {
            if ($car->state !== CarState::Repair || $car->costs()->exists()) {
                throw ValidationException::withMessages(['transport' => 'В гараже уже идёт работа с машиной: «Привёз» не отменить']);
            }
            $car->moveTo(CarState::Waiting, by: $by);
            GarageChanged::dispatch($car);
        }
        $t->update(['state' => TransportState::Moving, 'delivered_at' => null]);
        if ($offer) {
            ($this->place)($offer, CarPlace::Moving, $by);
        }
    }

    /** Исполнитель снят: у логиста — снова ему выбирать, иначе — ведущим. */
    private function unassign(Transport $t, User $by): void
    {
        if ($t->channel !== Channel::Logist) {
            $this->assign->release($t, $by);

            return;
        }
        $previous = $t->executor;
        $t->update(['executor_id' => null, 'state' => TransportState::Logist,
            'docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
        TransportEvent::record($t, 'released', $by, ['was' => $previous?->id]);
        TransportAssigned::dispatch($t, $previous);
    }

    /** «Забрал» / «Привёз» сами провели шаг сделки (`SyncHandover`) и маршрут ещё на нём — откатить и его. */
    private function unpressSale(Transport $t, Carbon $since, User $by): void
    {
        $offer = $t->saleOffer();
        $undo = $offer ? RouteStepBack::undoable($offer, Track::Sale) : null;
        $label = mb_strtolower(trim((string) ($undo['label'] ?? '')));
        if ($undo && $undo['at']->gte($since->copy()->subMinute()) && (in_array($label, Handover::PICKED, true) || $label === 'автомобиль передан')) {
            ($this->routeBack)($offer, Track::Sale, $by);
        }
    }
}
