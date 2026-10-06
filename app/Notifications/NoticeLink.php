<?php

namespace App\Notifications;

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
            Surface::Park => $href,
        };
    }

    private static function crm(string $href, string $path, ?string $subject): string
    {
        return match (true) {
            // Обращение с сайта: у гостя адрес «Контакты», сотруднику — чат в CRM (тема строки — `/account/chats/{id}`).
            $path === '/contacts' => self::chat($subject) ?? '/work/chats',
            (bool) preg_match('~^/account/chats/(\d+)$~', $path, $m) => '/work/chats/'.$m[1],
            (bool) preg_match('~^/(?:deals|account/money/deals)/(\d+)~', $path, $m) => '/work/deals/'.$m[1].self::fragment($href),
            // Машина гаража и вывоз — по номеру предложения, в CRM её ведут в редакторе.
            (bool) preg_match('~^/garage/(?:cars|pickups)/(\d+)~', $path, $m) => '/offers/'.$m[1],
            (bool) preg_match('~^/buyers/(\d+)$~', $path, $m) => '/settings/users/'.$m[1],
            // «Выставите счёт» прежнего вида: счета ставятся сами, форма без покупателя отвечала 404 — в деньги сделки.
            $path === '/work/invoices/new' => self::dealOfOffer($href) ?? '/work/deals',
            default => $href,
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
