<?php

namespace App\Http\Cabinet;

use Illuminate\Http\Request;

class NotificationController
{
    public function index(Request $request)
    {
        return view('cabinet.notifications', [
            'items' => $request->user()->notifications()->latest()->paginate(40),
            'unread' => $request->user()->unreadCount(),
        ]);
    }

    public function open(Request $request, string $id)
    {
        $item = $request->user()->notifications()->findOrFail($id);
        $item->markAsRead();

        return redirect($item->data['href'] ?? '/lk/uvedomleniya');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $user->update(['notification_settings' => ['mail' => $request->boolean('mail')] + ($user->notification_settings ?? [])]);

        return back()->with('toast', 'Сохранено');
    }
}
