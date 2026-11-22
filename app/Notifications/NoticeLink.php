<?php

namespace App\Notifications;

use App\Chats\Chat;
use App\Offers\OfferNumber;
use App\Support\Paths;
use App\Support\Surface;

/**
 * Куда ведёт уведомление в этом приложении. Адрес строки ленты пишется один раз, по главному приложению получателя, а
 * открывают её и на сайте, и в CRM: в CRM путь сайта (`/deals/7`, `/contacts`, `/garage/cars/…`) — «Такой страницы
 * нет» (владелец, 06.10.2026). Одна дверь для ленты, колокольчика, карточки и пуша: путь сайта → его место в CRM,
 * путь CRM на сайте — абсолютной ссылкой в CRM, старые адреса транслитом — через `Paths`.
 */
final class NoticeLink
{
    /**
     * Адрес с меткой открытия `nt=<id уведомления>.<канал>` — для пуша, Telegram и письма: перешли по нему — уведомление
     * открыто (`TrackNoticeOpen` → `Delivery::opened`, метку из адреса снимает). Лента и карточка отмечают маячком `seen`.
     */
    public static function tracked(string $url, Notice $notice, string $channel): string
    {
        if (! $notice->id) {
            return $url;
        }
        $hash = '';
        if (($at = strpos($url, '#')) !== false) {
            [$url, $hash] = [substr($url, 0, $at), substr($url, $at)];
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'nt='.$notice->id.'.'.$channel.$hash;
    }

    /** @param  array{href?: ?string, subject?: ?string}  $data  data строки ленты */
    public static function for(array $data, ?Surface $surface = null): string
    {
        $surface ??= Surface::current();
        $href = (string) ($data['href'] ?? '') ?: '/account/notifications';
        $subject = $data['subject'] ?? null;

        if (str_starts_with($href, 'http')) {
            $host = parse_url($href, PHP_URL_HOST);
            $local = self::local($href);
            if ($host === $surface->host()) {
                $href = $local;
            } elseif ($surface === Surface::Crm && $host === Surface::Site->host()) {
                $href = $local;
            } elseif ($surface === Surface::Mail && $host === Surface::Crm->host()) {
                // Письмо в CRM — то же письмо в XCAR Mail: адреса почты те же.
                $href = $local;
            } elseif ($surface === Surface::Pay && in_array($host, [Surface::Crm->host(), Surface::Site->host()], true)) {
                // «Оплаты» CRM и сайта — те же адреса в XCAR Pay, сделка — шторкой (`pay`).
                $href = $local;
            } else {
                return $href;
            }
        }
        $path = parse_url($href, PHP_URL_PATH) ?: '/';
        if ($legacy = Paths::translate($path)) {
            $href = $legacy.substr($href, strlen($path));
            $path = $legacy;
        }

        return match ($surface) {
            Surface::Crm => self::crm($href, $path, $subject),
            Surface::Site => self::site($href, $path, $subject),
            Surface::Park, Surface::Logistics, Surface::Partner => $href,
            // XCAR Chat: в нём только чаты — обращение гостя по своей теме, остальное — список чатов.
            Surface::Mail => self::mail($href, $path),
            Surface::Pay => self::pay($href, $path),
            Surface::Chat => match (true) {
                $path === '/contacts' => preg_match('~^/account/chats/(\d+)$~', (string) $subject, $m) ? Chat::path((int) $m[1]) : Chat::ROOT,
                (bool) preg_match('~^/chats(?:/\d+)?$~', $path) => $href,
                default => Chat::ROOT,
            },
        };
    }

    private static function crm(string $href, string $path, ?string $subject): string
    {
        return match (true) {
            // Обращение с сайта: у гостя адрес «Контакты», сотруднику — чат в CRM (тема строки — `Chat::subject`).
            // Адрес чата — `/chats/{id}`; у старых строк ленты — `/account/chats/{id}` (его уже перевёл `Paths::translate`).
            $path === '/contacts' => self::chat($subject) ?? '/work/chats',
            (bool) preg_match('~^/(?:account/|deals/)?chats/(\d+)$~', $path, $m) => '/work/chats/'.$m[1],
            (bool) preg_match('~^/(?:deals|account/money/deals)/(\d+)~', $path, $m) => '/work/deals/'.$m[1].self::fragment($href),
            // Машина гаража и вывоз — по номеру предложения, в CRM её ведут в редакторе.
            (bool) preg_match('~^/garage/(?:cars|pickups)/(\d+)~', $path, $m) => '/offers/'.$m[1],
            (bool) preg_match('~^/buyers/(\d+)$~', $path, $m) => '/settings/users/'.$m[1],
            // «Выставите счёт» прежнего вида: счета ставятся сами, форма без покупателя отвечала 404 — в деньги сделки.
            $path === '/work/invoices/new' => self::dealOfOffer($href) ?? '/offers?preset=agree',
            default => $href,
        };
    }

    /**
     * XCAR Mail: почта и «Из писем» — как есть; машина CRM (`/offers/N`, у письма поставщика — с окном её писем) — окно писем
     * этой машины поверх «Входящих», сделка из него — шторкой; остальное — «Входящие».
     */
    private static function mail(string $href, string $path): string
    {
        if (preg_match('~^/(?:work/mail|offers/from-mail)(?:/|$)~', $path)) {
            return $href;
        }
        if (preg_match('~^/offers/(\d+)(?:/letters)?$~', $path, $m)) {
            return '/work/mail?letters='.rawurlencode('/offers/'.$m[1].'/letters');
        }

        return '/work/mail';
    }

    /**
     * XCAR Pay (22.11.2026): «Оплаты» CRM и менеджера — как есть; сделка и машина — поверх «Оплат» шторкой (`?deal=` / `?car=`,
     * `x-ui.deal-sheet`): сотруднику CRM, менеджеру его сделка на сайте; остальное — корень, он ведёт по человеку.
     */
    private static function pay(string $href, string $path): string
    {
        return match (true) {
            (bool) preg_match('~^/account/money/deals/(\d+)~', $path, $m) => '/account/money?deal='.$m[1],
            (bool) preg_match('~^/(?:work/money|account/money)(?:/|$)~', $path) => $href,
            (bool) preg_match('~^/deals/(\d+)~', $path, $m) => '/account/money?deal='.$m[1],
            (bool) preg_match('~^/work/deals/(\d+)~', $path, $m) => '/work/money?deal='.$m[1],
            // Машина: корень уводит к «Оплатам» человека с тем же `?car=` (в очереди пуша не знаем, кто откроет).
            (bool) preg_match('~^/(?:offers|garage/cars)/(\d+)~', $path, $m) => '/?car='.$m[1],
            default => '/',
        };
    }

    private static function site(string $href, string $path, ?string $subject): string
    {
        return match (true) {
            $path === '/contacts' && self::chat($subject) !== null => Surface::Crm->url(self::chat($subject)),
            str_starts_with($path, '/work/') || str_starts_with($path, '/settings/') => Surface::Crm->url(ltrim($href, '/')),
            default => $href,
        };
    }

    private static function chat(?string $subject): ?string
    {
        return preg_match('~^/account/chats/(\d+)$~', (string) $subject, $m) ? '/work/chats/'.$m[1] : null;
    }

    private static function dealOfOffer(string $href): ?string
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $deal = isset($query['offer']) ? OfferNumber::find((string) $query['offer'])?->deal : null;

        return $deal ? '/work/deals/'.$deal->id.'#money' : null;
    }

    private static function local(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return (parse_url($url, PHP_URL_PATH) ?: '/').($query ? '?'.$query : '').self::fragment($url);
    }

    private static function fragment(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return $fragment ? '#'.$fragment : '';
    }
}
