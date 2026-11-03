<?php

namespace App\Media;

use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Кадр JPEG для мессенджеров из нашего webp: карточка закрытой машины в боте предложений (`Telegram\Offers\Card`), превью
 * PDF в WhatsApp (`WhatsApp\Group`).
 */
final class Jpeg
{
    /** JPEG не больше `side` px по длинной стороне; null — кадр не пережался. */
    public static function fit(Media $media, int $side, int $quality = 85): ?string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'jpeg');
        $tmp = $base.'.jpg';
        try {
            Image::load($media->getPath())->fit(Fit::Max, $side, $side)->format('jpg')->quality($quality)->save($tmp);

            return (string) file_get_contents($tmp) ?: null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmp);
            @unlink($base);
        }
    }
}
