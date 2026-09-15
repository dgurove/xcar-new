<?php

namespace App\Media;

use Illuminate\Support\Facades\Storage;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Водяной знак на кадре оффера с запретом шеринга: горизонтальный логотип
 * (resources/images/watermark.png из scripts/icons.mjs — двухтональный, читается
 * на любом кузове) кирпичной решёткой под наклоном на всё поле. Фаза решётки и
 * угол — свои у каждого кадра (seed = id медиа): чистых областей нет, одним
 * шаблоном знак не вычитается, а после инпейнта кузов заметно испорчен.
 * Печётся в сам оригинал на диске media, чистая копия — на private (cleanPath).
 * Голый GD: Imagick в образе нет, а spatie/image решётку с поворотом не умеет.
 */
final class Watermark
{
    /** Ширина логотипа — доля длинной стороны кадра. */
    public const TILE = 0.34;

    /** Шаги решётки в долях ширины и высоты логотипа; нечётные ряды сдвинуты на полшага. */
    public const PITCH_X = 1.2;

    public const PITCH_Y = 1.5;

    public const ANGLE = -25;

    public const ANGLE_JITTER = 6;

    public const QUALITY = 80;

    public static function cleanPath(int $mediaId): string
    {
        return Storage::disk('private')->path("clean/{$mediaId}.webp");
    }

    public static function apply(string $path, int $seed): void
    {
        $photo = @imagecreatefromwebp($path);
        if ($photo === false) {
            throw new RuntimeException("Кадр не читается: {$path}");
        }
        $logo = @imagecreatefrompng(resource_path('images/watermark.png'));
        if ($logo === false) {
            throw new RuntimeException('Нет resources/images/watermark.png — node scripts/icons.mjs');
        }
        $random = new Randomizer(new Mt19937($seed));
        $w = imagesx($photo);
        $h = imagesy($photo);

        $tileW = max(64, (int) round(max($w, $h) * self::TILE));
        $tile = imagescale($logo, $tileW);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        $tileH = imagesy($tile);

        // Прозрачный холст со стороной в диагональ кадра с запасом: после поворота кадр целиком внутри.
        $side = (int) ceil(hypot($w, $h)) + 2 * $tileW;
        $canvas = imagecreatetruecolor($side, $side);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $clear);

        $stepX = (int) round($tileW * self::PITCH_X);
        $stepY = (int) round($tileH * self::PITCH_Y);
        $phaseX = $random->getInt(0, $stepX - 1);
        $phaseY = $random->getInt(0, $stepY - 1);
        for ($row = 0, $y = -$stepY + $phaseY; $y < $side; $y += $stepY, $row++) {
            $shift = $row % 2 ? intdiv($stepX, 2) : 0;
            for ($x = -$stepX + $phaseX + $shift; $x < $side; $x += $stepX) {
                imagecopy($canvas, $tile, $x, $y, 0, 0, $tileW, $tileH);
            }
        }

        $angle = self::ANGLE + $random->getFloat(-self::ANGLE_JITTER, self::ANGLE_JITTER);
        $rotated = imagerotate($canvas, $angle, $clear);
        if ($rotated === false) {
            throw new RuntimeException('Решётка не повернулась');
        }
        imagesavealpha($rotated, true);

        imagealphablending($photo, true);
        imagecopy($photo, $rotated, 0, 0, intdiv(imagesx($rotated) - $w, 2), intdiv(imagesy($rotated) - $h, 2), $w, $h);

        $ok = imagewebp($photo, $path, self::QUALITY);
        if (! $ok) {
            throw new RuntimeException("Кадр не записался: {$path}");
        }
    }
}
