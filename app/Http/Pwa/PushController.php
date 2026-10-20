<?php

namespace App\Http\Pwa;

use App\Push\Subscription;
use App\Push\WebPushChannel;
use Illuminate\Http\Request;

class PushController
{
    public function store(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500'], 'keys.p256dh' => ['required', 'string'], 'keys.auth' => ['required', 'string']]);
        // Сверка при запуске приложения (push-sync.js): человек выключил уведомления на другом устройстве — не включаем.
        if ($request->boolean('sync') && ($request->user()->notification_settings['push'] ?? null) === false) {
            return response()->json(['ok' => false]);
        }
        Subscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id, 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'agent' => mb_substr((string) $request->userAgent(), 0, 255), 'host' => $request->getHost(), 'failed_at' => null,
        ]);
        $request->user()->update(['notification_settings' => ['push' => true] + ($request->user()->notification_settings ?? [])]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        Subscription::where('user_id', $request->user()->id)->when($endpoint, fn ($q) => $q->where('endpoint', $endpoint))->delete();
        $request->user()->update(['notification_settings' => ['push' => false] + ($request->user()->notification_settings ?? [])]);

        return response()->json(['ok' => true]);
    }

    /**
     * Пробный пуш в подписку этого браузера (20.10.2026): сразу после «Включить» и по «Проверить» в настройках. Показала
     * ли его система, сервер не знает — браузер спросит «Пришло?» (`confirm`). Чужая подписка — 404.
     */
    public function test(Request $request)
    {
        $sub = $this->own($request);
        $sub->update(['confirmed_at' => null, 'silent_at' => null]);

        return response()->json(['ok' => WebPushChannel::test($sub)]);
    }

    /** «Пришло?» — «Да» (`confirmed_at`) или «Нет» (`silent_at`): владелец видит в карточке человека, доходит ли. */
    public function confirm(Request $request)
    {
        $came = $request->boolean('came');
        $this->own($request)->update(['confirmed_at' => $came ? now() : null, 'silent_at' => $came ? null : now()]);

        return response()->json(['ok' => true]);
    }

    private function own(Request $request): Subscription
    {
        $endpoint = (string) $request->validate(['endpoint' => ['required', 'string', 'max:500']])['endpoint'];

        return Subscription::where('user_id', $request->user()->id)->where('endpoint', $endpoint)->firstOrFail();
    }
}
