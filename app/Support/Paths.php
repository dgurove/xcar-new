<?php

namespace App\Support;

/**
 * Адреса — по-английски. Старые сегменты транслитом (в письмах, уведомлениях в базе,
 * Telegram, закладках) переводятся этим словарём: RedirectLegacyPaths отдаёт 301.
 * Единственное место, где старые имена ещё существуют.
 */
final class Paths
{
    public const LEGACY = [
        'lk' => 'account',
        'profil' => 'profile',
        'izbrannoe' => 'favorites',
        'chaty' => 'chats',
        'interes' => 'interest',
        'interesy' => 'interests',
        'stavki' => 'confirmations',
        'stavka' => 'confirm',
        'sdelki' => 'deals',
        'otvet' => 'reply',
        'fayly' => 'files',
        'fayl' => 'file',
        'uvedomleniya' => 'notifications',
        'nastroyki' => 'settings',
        'prochitano' => 'read',
        'otkryto' => 'opened',
        'svezhie' => 'latest',
        'proverka' => 'check',
        'pokupateli' => 'buyers',
        'gruppy' => 'groups',
        'sostav' => 'members',
        'priglasheniya' => 'invites',
        'vkl' => 'on',
        'vykl' => 'off',
        'pokazy' => 'showings',
        'vybor' => 'pick',
        'parol' => 'password',
        'novyy' => 'new',
        'novyj' => 'new',
        'novoe' => 'new',
        'novaya' => 'new',
        'ssylka' => 'link',
        'otpiska' => 'unsubscribe',
        'podpiska' => 'subscription',
        'vhod' => 'login',
        'vyhod' => 'logout',
        'registraciya' => 'register',
        'poisk' => 'search',
        'galereya' => 'gallery',
        'zakupki' => 'purchases',
        'kontakty' => 'contacts',
        'voprosy' => 'faq',
        'obrabotka-dannyh' => 'privacy',
        'soglashenie' => 'terms',
        'soglasie' => 'consent',
        'oshibka' => 'error',
        'otozvat' => 'withdraw',
        'prinyat' => 'accept',
        'otklonit' => 'decline',
        'kabinet' => 'account',
        'eshchyo' => 'account',
        'predlozheniya' => 'offers',
        'iz-pisem' => 'from-mail',
        'zavesti' => 'create',
        'etap' => 'stage',
        'iskhod' => 'exit',
        'sostoyanie' => 'state',
        'prodlit' => 'extend',
        'vyvoz' => 'pickup',
        'poryadok' => 'order',
        'povernut' => 'rotate',
        'skryt' => 'hide',
        'rabota' => 'work',
        'pochta' => 'mail',
        'pisma' => 'messages',
        'razbor' => 'parse',
        'snova' => 'retry',
        'sinhronizaciya' => 'sync',
        'vlozheniya' => 'attachments',
        'neprochitano' => 'unread',
        'privyazka' => 'link',
        'zametka' => 'note',
        'polzovateli' => 'users',
        'dostup' => 'access',
        'strahovye' => 'insurers',
        'yashchiki' => 'mailboxes',
        'shablony' => 'templates',
        'tegi' => 'tags',
        'marshruty' => 'workflows',
        'bloki' => 'blocks',
        'etapy' => 'stages',
        'vklyuchit' => 'enable',
        'zapolnit' => 'fill',
        'zapusk' => 'launch',
        'spravochnik' => 'reference',
        'marki' => 'brands',
        'modeli' => 'models',
        'mashiny' => 'cars',
        'ceny' => 'prices',
        'otmenit' => 'cancel',
        'vybrat' => 'choose',
        'ogranicheniya' => 'limits',
        'vygruzka' => 'export',
        'cena' => 'price',
        'ocenka' => 'estimate',
        'perepiski' => 'chats',
        'soobshcheniya' => 'messages',
        'zayavki' => 'requests',
        'priem' => 'intake',
        'vydacha' => 'release',
        'zakryt' => 'close',
        'perestanovka' => 'move',
        'stoyanki' => 'yards',
        'klienty' => 'clients',
        'akty' => 'acts',
    ];

    /** Путь с переведёнными сегментами или null, если переводить нечего. */
    public static function translate(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));
        $changed = false;
        foreach ($segments as $i => $s) {
            if (isset(self::LEGACY[$s])) {
                $segments[$i] = self::LEGACY[$s];
                $changed = true;
            }
        }

        return $changed ? '/'.implode('/', $segments) : null;
    }
}
