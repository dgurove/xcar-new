<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Снять начисление можно только пока оно не в счёте; выставленное снимается аннулированием счёта. */
final class VoidCharge
{
    public function __invoke(Charge $charge, User $by, ?string $reason = null): Charge
    {
        if ($charge->invoice_id) {
            throw ValidationException::withMessages(['charge' => 'Начисление уже в счёте — аннулируйте счёт']);
        }
        $charge->update(['voided_at' => now(), 'void_reason' => $reason]);

        return $charge;
    }
}
