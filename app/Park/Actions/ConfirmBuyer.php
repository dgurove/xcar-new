<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Pass;
use App\Users\User;

/**
 * Страховая подтвердила покупателя — пропуск начинает работать. Отмечает сотрудник: прочитал ответ в переписке или
 * услышал по телефону при выдаче (тогда `note` — «устно, при выдаче»).
 */
final class ConfirmBuyer
{
    public function __invoke(Pass $pass, User $by, ?string $note = null): Pass
    {
        if ($pass->isConfirmed() || ! $pass->isLive()) {
            return $pass;
        }
        $pass->update(['confirmed_at' => now(), 'confirmed_by' => $by->id, 'confirm_note' => $note]);
        $pass->vehicle->log(EventType::BuyerConfirmed, $by, array_filter(['name' => $pass->name, 'note' => $note]));

        return $pass;
    }

    /** Страховая сказала «это не наш покупатель»: пропуск гаснет, та же ссылка снова открывает пустую анкету. */
    public function reject(Pass $pass, User $by, string $reason): void
    {
        if (! $pass->isLive()) {
            return;
        }
        $pass->update(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 255)]);
        $pass->vehicle->log(EventType::BuyerRejected, $by, ['name' => $pass->name, 'reason' => $reason]);
    }
}
