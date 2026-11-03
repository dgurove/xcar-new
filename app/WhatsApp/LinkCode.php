<?php

namespace App\WhatsApp;

use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Привязка WhatsApp к профилю (03.11.2026): человек сам пишет на номер владельца «Подключить XCar: K7P2QM4D» — ссылкой
 * `wa.me` с набранным текстом, — и привязывается тот WhatsApp, откуда пришло, а не номер из xcar (у Андрея они разные).
 * Переписку начал он — личные от владельца для WhatsApp ответ в чате, а не рассылка незнакомым.
 *
 * Код хранить не нужно: HMAC от человека и дня по Москве, восемь знаков без похожих (0/O, 1/I), действует сегодня и вчера
 * — ссылку открыли вечером, отправили утром. Чей код — перебором тех, кому WhatsApp служит (их десятки).
 */
final class LinkCode
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Код в тексте: «Подключить XCar: K7P2QM4D», регистр и пробелы — как получится. */
    public const PATTERN = '/xcar\s*:\s*([0-9a-z]{8})\b/iu';

    public static function for(User $user, ?Carbon $day = null): string
    {
        $day = ($day ?? now())->copy()->tz('Europe/Moscow')->toDateString();
        $hash = hash_hmac('sha256', "whatsapp:{$user->id}:{$day}", (string) config('app.key'), true);
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[ord($hash[$i]) & 31];
        }

        return $code;
    }

    /** Чей код: сегодняшний или вчерашний. */
    public static function owner(string $code): ?User
    {
        $code = strtoupper($code);
        $days = [now(), now()->subDay()];
        foreach (User::withRole(Role::Manager, Role::SuperBuyer)->whereNotNull('approved_at')->get() as $user) {
            foreach ($days as $day) {
                if (self::eligible($user) && hash_equals(self::for($user, $day), $code)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /** Кому WhatsApp служит: менеджерам и супер-покупателям — тем, кому открывают предложения и кого просят оценить. */
    public static function eligible(?User $user): bool
    {
        return $user !== null && ($user->isManager() || $user->isSuperBuyer()) && $user->isApproved() && ! $user->isRejected() && ! $user->is_demo;
    }

    public static function text(User $user): string
    {
        return 'Подключить XCar: '.self::for($user);
    }

    /** Ссылка с набранным текстом: на телефоне открывает WhatsApp, на ПК — WhatsApp Web или приложение. */
    public static function url(User $user): string
    {
        return 'https://wa.me/'.config('xcar.whatsapp.number').'?text='.rawurlencode(self::text($user));
    }

    /** Сразу в приложение на телефоне. */
    public static function app(User $user): string
    {
        return 'whatsapp://send?phone='.config('xcar.whatsapp.number').'&text='.rawurlencode(self::text($user));
    }
}
