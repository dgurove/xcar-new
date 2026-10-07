<?php

namespace App\Users\Access;

use App\Live\Publisher;
use App\Users\User;

/**
 * Устройства человека гаснут: их сессии при следующем запросе выходят (метка в cookie больше не совпадает), открытые
 * страницы узнают сразу — событием в тему устройства. Зовут новое устройство (`Enroll`) и админ, отвязавший Telegram.
 */
final class RevokeDevices
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(User $user, ?Device $except = null): void
    {
        $devices = Device::where('user_id', $user->id)->whereNull('revoked_at')
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->get();
        if ($devices->isEmpty()) {
            return;
        }
        Device::whereKey($devices->modelKeys())->update(['revoked_at' => now()]);
        foreach ($devices as $device) {
            ($this->publish)($device->topic, 'lock', ['state' => Lock::KICKED]);
        }
    }
}
