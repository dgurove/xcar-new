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
 *
 * Без ключа — облегчённый режим «одно устройство» (Lock::lite): окна нет, срок — сейчас. Включат полную защиту — такое
 * устройство сразу замёрзнет и подтвердится кодом, ключ браузера привяжется к нему при подтверждении (Confirm).
 */
final class Enroll
{
    /** Пространство замков `pg_advisory_xact_lock(LOCK, user_id)`. */
    private const LOCK = 7101;

    public function __construct(private RevokeDevices $revoke) {}

    /** @param array{kty: string, crv: string, x: string, y: string}|null $key */
    public function __invoke(Request $request, User $user, ?array $key = null): Device
    {
        $token = Str::random(40);
        Lock::rememberCode($request, null);
        Lock::remember($request, $user->id, $token);
        $topic = Lock::topic($request);

        $device = DB::connection('pgsql_async')->transaction(function () use ($user, $token, $key, $request, $topic) {
            // Два входа разом (двойное нажатие «Войти через Telegram», 17.10.2026 у Дашкова) — по очереди: второй ждёт
            // первого, гасит его устройство и встаёт сам. Без замка оба гасили старое и вставляли новое — 500 на
            // `devices_live_user` (живое устройство у человека одно).
            DB::connection('pgsql_async')->select('select pg_advisory_xact_lock(?, ?)', [self::LOCK, $user->id]);
            ($this->revoke)($user);

            return Device::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'public_key' => $key ? [$request->getHost() => $key] : [],
                'label' => UserAgent::label($request->userAgent()),
                'ip' => $request->ip(),
                'topic' => $topic,
                'verified_until' => $key ? now()->addMinutes(Lock::minutes()) : now(),
            ]);
        });
        Lock::forget($request);

        return $device;
    }
}
