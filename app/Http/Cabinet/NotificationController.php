<?php

namespace App\Http\Cabinet;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\Categories;
use App\Notifications\TestNotice;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;

class NotificationController
{
    public function index(Request $request)
    {
        return view('cabinet.notifications', [
            'items' => $request->user()->noticesHere()->latest()->paginate(40),
            'unread' => $request->user()->unreadCount(),
        ]);
    }

    /** Пять последних — для шторки колокольчика. */
    public function latest(Request $request)
    {
        return view('cabinet.notifications-latest', [
            'items' => $request->user()->noticesHere()->latest()->limit(5)->get(),
            'frame' => $request->header('Turbo-Frame', 'notifications-latest'),
        ]);
    }

    public function open(Request $request, string $id)
    {
        $item = $request->user()->notifications()->findOrFail($id);
        Impersonation::active() || $item->markAsRead();

        return redirect($item->data['href'] ?? '/account/notifications');
    }

    /** Тап по строке ведёт сразу на объект; прочитанность отмечается маячком с клиента, значки — во всех вкладках. */
    public function seen(Request $request, string $id, Publisher $publish)
    {
        $item = Impersonation::active() ? null : $request->user()->unreadNotifications()->whereKey($id)->first();
        if ($item) {
            $item->markAsRead();
            $publish->badges(Topics::user($request->user()));
        }

        return response()->noContent();
    }

    /** Смахнули строку: прочитано ↔ не прочитано, ответ — та же строка стримом. Осознанное действие — и под «Войти как». */
    public function toggleRead(Request $request, string $id, Publisher $publish)
    {
        $item = $request->user()->notifications()->findOrFail($id);
        $item->read_at ? $item->markAsUnread() : $item->markAsRead();
        $publish->badges(Topics::user($request->user()));

        return response()
            ->view('cabinet.notification-row-stream', ['item' => $item->fresh()])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public function readAll(Request $request, Publisher $publish)
    {
        // Колокольчик сам шлёт «прочитано» при открытии (ajax): за человека это не отмечается.
        // Кнопка «Всё прочитано» — осознанное действие, её слушаемся, как свайпа по строке.
        if (! ($request->ajax() && Impersonation::active()) && $request->user()->noticesHere()->whereNull('read_at')->update(['read_at' => now()])) {
            $publish->badges(Topics::user($request->user()));
        }

        return $request->ajax() ? response()->noContent() : back();
    }

    /** Экран настроек: каналы, о чём, тихие часы; строки сохраняются сами. */
    public function settingsPage(Request $request)
    {
        $user = $request->user();

        return view('cabinet.notification-settings', [
            'user' => $user,
            'categories' => Categories::for($user),
            'telegram' => Categories::telegram($user),
            'settings' => $user->notification_settings ?? [],
        ]);
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $allowed = array_keys(Categories::for($user)['on']);
        $inTelegram = array_keys(Categories::telegram($user));
        // Прочие ключи (пуш, «напомнить позже» про Telegram) форма не присылает — их не теряем.
        $user->update(['notification_settings' => [
            'mail' => $request->boolean('mail'),
            'sound' => $request->boolean('sound'),
            'quiet' => $request->boolean('quiet'),
            // Форма присылает включённые категории — выключенные считаем от разрешённых.
            'off' => array_values(array_diff($allowed, array_map('strval', (array) $request->input('on', [])))),
        ] + ($user->telegram_chat_id ? ['telegram' => $request->boolean('telegram')] : [])
            // Что не слать в Telegram: из того, что туда вообще идёт, — не отмеченное (лента и пуш — по `off`). Строк не было
            // на экране (Telegram выключен) — прежний выбор не трогаем.
            + ($request->boolean('tg_shown') ? ['telegram_off' => array_values(array_diff($inTelegram, array_map('strval', (array) $request->input('tg', []))))] : [])
            + ($user->notification_settings ?? [])]);

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
