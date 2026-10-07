<?php

namespace App\Support;

/** Браузер и система словами: «Safari, iPhone». Видно в боте при входе и подтверждении — какой браузер просит. */
final class UserAgent
{
    public static function label(?string $ua): string
    {
        $ua = (string) $ua;
        $browser = match (true) {
            str_contains($ua, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Браузер',
        };
        $system = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? $browser.', '.$system : $browser;
    }
}
