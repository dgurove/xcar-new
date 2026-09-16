<?php

namespace App\Http\Cabinet;

use App\Notifications\Categories;
use App\Notifications\TestNotice;
use App\Users\User;
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

    /** Тап по строке ведёт сразу на объект; прочитанность отмечается маячком с клиента. */
    public function seen(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->first()?->markAsRead();

        return response()->noContent();
    }

    /** Смахнули строку: прочитано ↔ не прочитано, ответ — та же строка стримом. */
    public function toggleRead(Request $request, string $id)
    {
        $item = $request->user()->notifications()->findOrFail($id);
        $item->read_at ? $item->markAsUnread() : $item->markAsRead();

        return response()
            ->view('cabinet.notification-row-stream', ['item' => $item->fresh()])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->ajax() ? response()->noContent() : back();
    }

    /** Экран настроек: каналы, о чём, тихие часы; строки сохраняются сами. */
    public function settingsPage(Request $request)
    {
        $user = $request->user();

        return view('cabinet.notification-settings', [
            'user' => $user,
            'categories' => Categories::for($user->role),
            'settings' => $user->notification_settings ?? [],
        ]);
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $allowed = array_keys(Categories::for($user->role)['on']);
        $user->update(['notification_settings' => [
            'mail' => $request->boolean('mail'),
            'digest' => $request->boolean('digest'),
            'quiet' => $request->boolean('quiet'),
            // Форма присылает включённые категории — выключенные считаем от разрешённых.
            'off' => array_values(array_diff($allowed, array_map('strval', (array) $request->input('on', [])))),
        ]]);

        return back()->with('toast', 'Сохранено');
    }

    public function test(Request $request)
    {
        $request->user()->notify(new TestNotice);

        return back()->with('toast', 'Отправили пробное уведомление');
    }

    /** Из письма, без входа: подписанная ссылка выключает почту; «Вернуть» — включает. */
    public function unsubscribe(Request $request, User $user)
    {
        if ($request->isMethod('post')) {
            $user->update(['notification_settings' => ['mail' => true] + ($user->notification_settings ?? [])]);

            return back()->with('toast', 'Письма снова приходят');
        }
        if ($user->wantsMail()) {
            $user->update(['notification_settings' => ['mail' => false] + ($user->notification_settings ?? [])]);
        }

        return view('cabinet.unsubscribed', ['user' => $user]);
    }
}
