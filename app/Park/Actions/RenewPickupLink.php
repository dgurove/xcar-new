<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Pass;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * «Отключить и выдать новую»: ссылку переслали не тому или потеряли. Живой пропуск гаснет, у ТС новый код анкеты,
 * прежний остаётся в ленте (`old`) — по нему анкета отвечает «ссылка больше не действует», а не «код не найден».
 */
final class RenewPickupLink
{
    public function __invoke(Vehicle $vehicle, ?User $by): void
    {
        DB::transaction(function () use ($vehicle, $by) {
            $old = $vehicle->pickup_code;
            $vehicle->revokePass('ссылка заменена');
            do {
                $code = Pass::freshCode();
            } while (Vehicle::where('pickup_code', $code)->exists());
            $vehicle->forceFill(['pickup_code' => $code])->saveQuietly();
            if ($old) {
                $vehicle->log(EventType::PickupLinkRenewed, $by, ['old' => $old]);
            }
        });
    }
}
