<?php

namespace App\Live;

use App\Chats\GuestEnquiry;
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
        if (Jwt::topicsOf($request->cookie(self::NAME)) !== $topics) {
            $response->headers->setCookie(new Cookie(
                self::NAME, Jwt::subscriber($topics), now()->addMinutes((int) config('session.lifetime')),
                '/.well-known/mercure', config('session.domain'), $request->isSecure(), true, false, 'lax',
            ));
        }

        return $response;
    }
}
