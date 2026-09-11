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

    /** Пять последних — для шторки колокольчика. */
    public function latest(Request $request)
    {
        return view('cabinet.notifications-latest', [
            'items' => $request->user()->notifications()->latest()->limit(5)->get(),
            'frame' => $request->header('Turbo-Frame', 'notifications-latest'),
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

        return $request->ajax() ? response()->noContent() : back();
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $user->update(['notification_settings' => ['mail' => $request->boolean('mail')] + ($user->notification_settings ?? [])]);

        return back()->with('toast', 'Сохранено');
    }
}
