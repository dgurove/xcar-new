<?php

namespace App\Offers\Actions;

use App\Garage\Actions\EnsurePickup;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Users\User;

/**
 * Кто забирает ТС у владельца, когда её отдали (`Give`). Гаражная — везут к менеджеру в гараж, вывоз есть всегда
 * (`EnsurePickup`, где ТС сейчас — `$where`). Сделка покупателя (04.10.2026; с 17.10.2026 — задача логистики): забирает
 * менеджер — он исполнитель вывоза; «Логистика» — вывоз ждёт, кого выберет админ, а если вывозил прежний покупатель
 * сделки (отдали другому), его поручение снимается.
 */
final class AssignDealPickup
{
    public function __construct(private EnsurePickup $ensure, private OpenPickup $open, private AssignExecutor $assign) {}

    /** @param  'manager'|'us'  $pickup */
    public function __invoke(Offer $offer, Deal $deal, string $pickup, User $by, string $where = 'owner', ?Deal $previous = null): void
    {
        $manager = $pickup === 'manager' ? $deal->buyer : null;
        if ($deal->isGarage()) {
            ($this->ensure)($offer, $by, $manager, $where);

            return;
        }
        $offer = $offer->fresh(['parkVehicle']);
        if (! $offer->pickupChoosable()) {
            return;
        }
        $t = Transport::openFor($offer);
        $wasPicker = $previous && $t?->channel === Channel::Manager && $t->executor_id === $previous->buyer_id;
        if ($manager) {
            $t ??= ($this->open)($offer, $by, quiet: true);
            ($this->assign)($t, Channel::Manager, $manager, $by);
        } elseif ($wasPicker) {
            $this->assign->release($t, $by);
        } elseif (! $t) {
            ($this->open)($offer, $by);
        }
    }
}
