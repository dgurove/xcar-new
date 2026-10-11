<?php

namespace App\Media;

use Illuminate\Support\Facades\Cache;

/**
 * Скан документа среди фото машины (12.10.2026, владелец: заявка Альфы с оценочной стоимостью приходит картинкой
 * «Image_001.jpg» рядом с кадрами, её никто не читал; «пусть сканы документов из фото в документы переносит»). Признак —
 * по уменьшенной до 64 px картинке: у скана почти всё белое и бесцветное (белого 0,87–0,98 на заявках Альфы), у кадра
 * машины белого не больше 0,41 даже на белом кузове. Вердикт помнится по отпечатку содержимого (`sha256`): вложение письма
 * и кадр с тем же содержимым дальше узнаются без расчёта (`known`).
 */
final class DocumentScan
{
    private const WHITE = 0.7;

    private const COLOR = 0.03;

    public static function is(string $contents): bool
    {
        $sha = hash('sha256', $contents);
        $known = self::known($sha);
        if ($known !== null) {
            return $known;
        }
        $verdict = self::measure($contents);
        Cache::forever(self::key($sha), $verdict ? 1 : 0);

        return $verdict;
    }

    /** Вердикт без расчёта: true — скан, false — фото, null — ещё не смотрели. */
    public static function known(?string $sha): ?bool
    {
        if (! $sha) {
            return null;
        }
        $v = Cache::get(self::key($sha));

        return $v === null ? null : (bool) $v;
    }

    private static function measure(string $contents): bool
    {
        $img = @imagecreatefromstring($contents);
        if ($img === false) {
            return false;
        }
        $small = imagescale($img, 64);
        if ($small === false) {
            return false;
        }
        [$w, $h] = [imagesx($small), imagesy($small)];
        $white = $color = 0;
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $c = imagecolorat($small, $x, $y);
                [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $max = max($r, $g, $b);
                $sat = $max ? ($max - min($r, $g, $b)) / $max : 0;
                $white += (int) ($max > 200 && $sat < 0.15);
                $color += (int) ($sat > 0.35 && $max > 60);
            }
        }
        $n = max(1, $w * $h);

        return $white / $n >= self::WHITE && $color / $n <= self::COLOR;
    }

    private static function key(string $sha): string
    {
        return 'docscan:'.$sha;
    }
}
