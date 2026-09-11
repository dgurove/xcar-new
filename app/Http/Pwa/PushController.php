<?php

namespace App\Http\Pwa;

use App\Push\Subscription;
use Illuminate\Http\Request;

class PushController
{
    public function store(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500'], 'keys.p256dh' => ['required', 'string'], 'keys.auth' => ['required', 'string']]);
        Subscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id, 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'agent' => mb_substr((string) $request->userAgent(), 0, 255), 'failed_at' => null,
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
}
