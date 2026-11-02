<?php

namespace App\Live;

use App\Chats\GuestEnquiry;
use App\Users\Access\Lock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Cookie с токеном подписчика на путь хаба. Переписывается, только когда набор тем изменился. */
final class SubscriberCookie
{
    public const NAME = 'mercureAuthorization';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! config('xcar.mercure.subscriber_key')) {
            return $response;
        }
        $topics = Topics::for($request->user(), app(GuestEnquiry::class)->chat($request)?->id);
        // Связанные аккаунты (`UseLinkedAccount`): кука одна на все хосты — темы обоих, иначе вкладки парковки и сайта
        // перетирали бы подписку друг другу.
        if ($linked = $request->user()?->linked) {
            $topics = array_values(array_unique([...$topics, ...Topics::for($linked)]));
            sort($topics);
        }
        // Менеджер под замком: пропуск в хаб живёт до конца окна, дальше хаб сам рвёт поток (Mercure ≥ 0.14).
        $exp = Lock::until($request);
        $token = $request->cookie(self::NAME);
        if (Jwt::topicsOf($token) !== $topics || Jwt::expOf($token) !== $exp) {
            $response->headers->setCookie(new Cookie(
                self::NAME, Jwt::subscriber($topics, $exp), now()->addMinutes((int) config('session.lifetime')),
                '/.well-known/mercure', config('session.domain'), $request->isSecure(), true, false, 'lax',
            ));
        }

        return $response;
    }
}
