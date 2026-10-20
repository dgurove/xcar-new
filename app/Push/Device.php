<?php

namespace App\Push;

/**
 * Устройство подписки словами для карточки человека: «Chrome на Mac», «Яндекс Браузер на Android», «iPhone». Браузер
 * важен для подсказки, где разрешить уведомления: у каждого он свой в системных настройках.
 */
final class Device
{
    public static function label(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        // На iPhone и iPad пуши — только у приложения на экране «Домой», браузер там значения не имеет.
        if (in_array($os, ['iPhone', 'iPad'], true)) {
            return $os;
        }
        $browser = match (true) {
            str_contains($agent, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Браузер',
        };

        return $os ? $browser.' на '.$os : $browser;
    }
}
