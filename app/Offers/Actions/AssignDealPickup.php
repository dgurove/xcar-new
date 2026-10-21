<?php

namespace App\Offers\Actions;

use App\Garage\Actions\EnsurePickup;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Backdate;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Channel;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Кто забирает ТС у владельца, когда её отдали (`Give`). Гаражная — везут к менеджеру в гараж, вывоз есть всегда
 * (`EnsurePickup`, где ТС сейчас — `$where`). Сделка покупателя (04.10.2026; с 17.10.2026 — задача логистики): забирает
 * менеджер — он исполнитель вывоза; «Логистика» — вывоз ждёт, кого выберет админ, а если вывозил прежний покупатель
 * сделки (отдали другому), его поручение снимается. «Не определено» (21.10.2026) — вывоз заводится тихо, без исполнителя и
 * с меткой `undecided`: ни «Вывоз: …» админам, ни сводки; ход наш, менеджер видит «Кто забирает, решаем».
 */
final class AssignDealPickup
{
    public function __construct(private EnsurePickup $ensure, private OpenPickup $open, private AssignExecutor $assign, private Backdate $backdate) {}

    /**
     * «Уже забрал» (`taken`, 21.10.2026, владелец: «когда сделку начинаешь, не хватает даты на случай, если уже вывезли»):
     * менеджер сделки уже увёз машину к себе — вывоз его и закрыт «Привёз» датой `$at` (`Backdate`): место «у менеджера»,
     * шаг «Менеджер забирает автомобиль» проходит сам (`SyncHandover`).
     *
     * @param  'later'|'manager'|'us'|'taken'  $pickup
     */
    public function __invoke(Offer $offer, Deal $deal, string $pickup, User $by, string $where = 'owner', ?Deal $previous = null, ?Carbon $at = null): void
    {
        $manager = in_array($pickup, ['manager', 'taken'], true) ? $deal->buyer : null;
        $later = $pickup === 'later';
        if ($deal->isGarage()) {
            ($this->ensure)($offer, $by, $manager, $where, $later, $at);

            return;
        }
        $offer = $offer->fresh(['parkVehicle']);
        if (! $offer->pickupChoosable()) {
            return;
        }
        $t = Transport::openFor($offer);
        if ($pickup === 'taken' && $manager) {
            $t ??= ($this->open)($offer, $by, quiet: true);
            if (! $t->isExecutedBy($manager)) {
                $t = ($this->assign)($t, Channel::Manager, $manager, $by);
            }
            ($this->backdate)($t->fresh(), ['picked_at' => ($at ?? now())->format('Y-m-d H:i'), 'destination' => Point::Keeper->value], $by);

            return;
        }
        $wasPicker = $previous && $t?->channel === Channel::Manager && $t->executor_id === $previous->buyer_id;
        if ($manager) {
            $t ??= ($this->open)($offer, $by, quiet: true);
            ($this->assign)($t, Channel::Manager, $manager, $by);
        } elseif ($wasPicker) {
            $this->assign->release($t, $by);
            self::undecided($t->fresh(), $later);
        } elseif (! $t) {
            self::undecided(($this->open)($offer, $by, quiet: $later), $later);
        } else {
            self::undecided($t, $later);
        }
    }

    /** «Не определено» — метка, пока задача ждёт решения и никого не назначили. */
    public static function undecided(Transport $t, bool $later): void
    {
        if ($later && $t->state === TransportState::New && ! $t->channel && ! $t->undecided) {
            $t->update(['undecided' => true]);
        }
    }
}
