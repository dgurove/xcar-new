<?php

namespace App\Users\Access;

use App\Support\UserAgent;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Этот браузер — устройство человека, прежнее выкидывается (07.10.2026, владелец: «вход на другом устройстве выбрасывает
 * из аккаунта на всех других»). Прежнее узнаёт сразу — событием в тему своей сессии, а его следующий запрос упирается в
 * сторож (`kicked`): метка в его cookie больше не совпадает. Скопированная cookie живёт не дольше окна: подтвердить её
 * нечем — ключ браузера не копируется, а подтверждение другим ключом заводит новое устройство и выкидывает копию.
 * Тема хаба — новая, иначе «выкинуть» дошло бы и до себя.
 */
final class Enroll
{
    public function __construct(private RevokeDevices $revoke) {}

    /** @param array{kty: string, crv: string, x: string, y: string} $key */
    public function __invoke(Request $request, User $user, array $key): Device
    {
        $token = Str::random(40);
        Lock::rememberCode($request, null);
        Lock::remember($request, $user->id, $token);
        $topic = Lock::topic($request);

        $device = DB::connection('pgsql_async')->transaction(function () use ($user, $token, $key, $request, $topic) {
            ($this->revoke)($user);

            return Device::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'public_key' => [$request->getHost() => $key],
                'label' => UserAgent::label($request->userAgent()),
                'ip' => $request->ip(),
                'topic' => $topic,
                'verified_until' => now()->addMinutes(Lock::minutes()),
            ]);
        });
        Lock::forget($request);

        return $device;
    }
}
