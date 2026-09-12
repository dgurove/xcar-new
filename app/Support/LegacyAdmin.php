<?php

namespace App\Support;

/** Старые адреса админки xcar.ru/admin/… → CRM. Первый сегмент по карте, хвост и строка запроса как есть. */
final class LegacyAdmin
{
    private const MAP = [
        'offers' => '/predlozheniya',
        'pochta' => '/rabota/pochta',
        'chaty' => '/rabota/chaty',
        'sdelki' => '/rabota/sdelki',
        'zakupki' => '/zakupki',
        'stavki' => '/stavki',
        'interesy' => '/interesy',
        'strahovye' => '/nastroyki/strahovye',
        'marshruty' => '/nastroyki/marshruty',
        'kandidaty' => '/predlozheniya/iz-pisem',
        'galereya' => '/galereya',
        'yashchiki' => '/nastroyki/yashchiki',
        'shablony' => '/nastroyki/shablony',
        'spravochnik' => '/spravochnik',
        'eshchyo' => '/lk',
        'polzovateli' => '/nastroyki/polzovateli',
        'tegi' => '/nastroyki/tegi',
        'ui' => '/ui',
    ];

    public static function target(string $path, ?string $query = null): string
    {
        $segments = explode('/', trim($path, '/'), 2);
        $head = self::MAP[$segments[0]] ?? '/';
        $tail = isset($segments[1]) ? '/'.$segments[1] : '';
        if ($head === '/predlozheniya' && $tail === '') {
            $head = '/';
        }

        return Surface::Crm->url($head.$tail).($query ? '?'.$query : '');
    }
}
