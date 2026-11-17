<?php

namespace App\Http\Live;

use App\Notifications\ReadNotices;
use App\Offers\Offer;
use App\Offers\ReadOffer;
use App\Support\Surface;
use App\Users\Impersonation;
use Illuminate\Http\Request;
use Throwable;

/**
 * Страницу, загруженную заранее, и правда открыли (17.11.2026). Предзагрузка по касанию (`touch-prefetch.js`, разделы таб-бара
 * и шапки — `lib/sections.js`) приходит с `X-Sec-Purpose: prefetch`, и сервер честно ничего не отмечает: человек мог и не
 * нажать. Нажал — переход берёт готовый ответ, и отметки «прочитано» так и не случались: уведомления о разделе не гасли,
 * предложение на сайте оставалось «не просмотренным» (`ReadOffer`), хотя его открыли. Клиент сообщает об этом здесь —
 * то же, что сделал бы обычный заход: уведомления по адресу (`ReadNoticesOnVisit`) и просмотр предложения сайта.
 */
class VisitedController
{
    public function __invoke(Request $request, ReadNotices $read)
    {
        $user = $request->user();
        $url = (string) $request->input('url');
        $parts = parse_url($url);
        if (! $user || Impersonation::active() || ! $parts || ($parts['host'] ?? $request->getHost()) !== $request->getHost()) {
            return response()->noContent();
        }
        $path = '/'.ltrim($parts['path'] ?? '/', '/');
        $uri = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
        $read([$user->id], array_values(array_unique([$path, $uri])), Surface::current());

        // Страница предложения на сайте — как в `Site\OfferController::show`: видно человеку — просмотрено.
        if (Surface::current() === Surface::Site && preg_match('#^/offers/([^/]+)$#', $path, $m)) {
            try {
                $offer = (new Offer)->resolveRouteBinding($m[1]);
                if ($offer && $offer->isVisibleTo($user)) {
                    ReadOffer::mark($user, $offer);
                }
            } catch (Throwable) {
                // чужой или кривой номер — не отмечаем
            }
        }

        return response()->noContent();
    }
}
