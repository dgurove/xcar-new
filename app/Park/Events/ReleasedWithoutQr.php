<?php

namespace App\Park\Events;

use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** У вендора с выдачей по QR выдали без кода — владельцу в Telegram, чтобы обход не стал привычкой. */
final class ReleasedWithoutQr
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public User $by, public string $reason) {}
}
