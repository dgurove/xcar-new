<?php

namespace App\Http\Cabinet;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\Categories;
use App\Notifications\Delivery;
use App\Notifications\NoticeLink;
use App\Notifications\TestNotice;
use App\Push\Subscription;
use App\Push\WebPushChannel;
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
        Delivery::opened($request->user(), $id, 'feed');

        return redirect(NoticeLink::for($item->data));
    }

    /**
     * Тап по строке ленты или карточке (`via=banner`) ведёт сразу на объект; прочитанность и открытие (`Delivery::opened`)
     * отмечаются маячком с клиента, значки — во всех вкладках.
     */
    public function seen(Request $request, string $id, Publisher $publish)
    {
        Delivery::opened($request->user(), $id, $request->input('via') === 'banner' ? 'banner' : 'feed');
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
            'mail' => Categories::mail($user),
            'settings' => $user->notification_settings ?? [],
        ]);
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $allowed = array_keys(Categories::for($user)['on']);
        $inTelegram = array_keys(Categories::telegram($user));
        // Прочие ключи (пуш, «напомнить позже» про Telegram) форма не присылает — их не теряем.
        $mailOn = array_values(array_intersect(array_keys(Categories::mail($user)), array_map('strval', (array) $request->input('mail_on', []))));
        $user->update(['notification_settings' => [
            'sound' => $request->boolean('sound'),
            'quiet' => $request->boolean('quiet'),
            // Форма присылает включённые категории — выключенные считаем от разрешённых.
            'off' => array_values(array_diff($allowed, array_map('strval', (array) $request->input('on', [])))),
        ] + ($user->telegram_chat_id ? ['telegram' => $request->boolean('telegram')] : [])
            // Что не слать в Telegram: из того, что туда вообще идёт, — не отмеченное (лента и пуш — по `off`). Строк не было
            // на экране (Telegram выключен) — прежний выбор не трогаем.
            + ($request->boolean('tg_shown') ? ['telegram_off' => array_values(array_diff($inTelegram, array_map('strval', (array) $request->input('tg', []))))] : [])
            // На почту — отмеченное из `Categories::mail`; включил хоть что-то — отписка из письма снимается. Почта не
            // подтверждена — тумблеры выключены и не приходят: прежний выбор не трогаем.
            + ($request->boolean('mail_shown') && $user->mailVerified() ? ['mail_on' => $mailOn] + ($mailOn ? ['mail' => true] : []) : [])
            + ($user->notification_settings ?? [])]);

        return back()->with('toast', 'Сохранено');
    }

    /** «Подтвердить» у почты в настройках: письмо со ссылкой (`SecurityLetter::verify`). */
    public function sendVerify(Request $request)
    {
        $user = $request->user();

        return back()->with('toast', \App\Users\SecurityLetter::verify($user) ? 'Письмо отправлено на '.$user->email : ($user->mailVerified() ? 'Почта уже подтверждена' : 'Письмо уже отправлено, проверьте почту'));
    }

    /** Ссылка из письма: тот же адрес, что был, — почта подтверждена; адрес сменили после письма — нет. */
    public function verifyMail(Request $request, User $user, string $hash)
    {
        $ok = $user->email && hash_equals(sha1((string) $user->email), $hash);
        if ($ok && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        }

        return view('cabinet.mail-verified', ['user' => $user, 'ok' => $ok]);
    }

    public function test(Request $request)
    {
        $request->user()->notify(new TestNotice);
        // Пуш — на каждое устройство, мимо фильтра приложения: подписку логистики и парковки «Проверить» раньше не задевало.
        Subscription::where('user_id', $request->user()->id)->get()->each(fn (Subscription $sub) => WebPushChannel::test($sub));

        return back()->with('toast', 'Отправили пробное уведомление');
    }

    /**
     * Из письма, без входа: подписанная ссылка выключает почту; «Вернуть» — включает. Почтовик отписывает одним нажатием
     * POST-ом с `List-Unsubscribe=One-Click` (RFC 8058) — это отписка, а не «Вернуть»; токена у него нет (исключение CSRF).
     */
    public function unsubscribe(Request $request, User $user)
    {
        if ($request->isMethod('post') && $request->input('List-Unsubscribe') === 'One-Click') {
            $user->update(['notification_settings' => ['mail' => false] + ($user->notification_settings ?? [])]);

            return response()->noContent();
        }
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
