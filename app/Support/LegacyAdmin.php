<?php

namespace App\Support;

/** Старые адреса админки xcar.ru/admin/… → CRM. Первый сегмент по карте, хвост и строка запроса как есть. */
final class LegacyAdmin
{
    private const MAP = [
        'offers' => '/offers',
        'pochta' => '/work/mail',
        'chaty' => '/work/chats',
        'sdelki' => '/work/deals',
        'zakupki' => '/purchases',
        'stavki' => '/offers?preset=bids',
        'interesy' => '/interests',
        'strahovye' => '/settings/vendors',
        'marshruty' => '/settings/workflows',
        'kandidaty' => '/offers/from-mail',
        'galereya' => '/gallery',
        'yashchiki' => '/settings/mailboxes',
        'shablony' => '/settings/templates',
        'spravochnik' => '/reference',
        'eshchyo' => '/settings',
        'polzovateli' => '/settings/users',
        'tegi' => '/settings/tags',
        'ui' => '/ui',
    ];

    public static function target(string $path, ?string $query = null): string
    {
        $segments = explode('/', trim($path, '/'), 2);
        // Корень старой админки и незнакомое — в предложения: они и были её первым экраном.
        $head = self::MAP[$segments[0]] ?? '/offers';
        $tail = isset($segments[1]) ? '/'.$segments[1] : '';

        return Surface::Crm->url($head.$tail).($query ? '?'.$query : '');
    }
}
