<?php

namespace App\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class MediaUrl
{
    /**
     * Телефон с плотным экраном (3x) на кадре во всю ширину просил бы ~1170 px и брал оригинал: браузеру говорим «320 px»,
     * он выбирает w960 — на экране не отличить, а весит вдвое-втрое меньше и не забивает память телефона.
     */
    private const PHONE_DENSE = '(max-width: 639.98px) and (min-resolution: 2.5dppx) 320px';

    /** sizes для srcset: если на телефоне кадр во всю ширину (последнее значение в vw) — с потолком для 3x. */
    public static function sizes(string $sizes): string
    {
        $phone = trim((string) last(explode(',', $sizes)));

        return str_contains($phone, 'vw') && ! str_contains($sizes, 'dppx') ? self::PHONE_DENSE.', '.$sizes : $sizes;
    }

    /** Адрес конверсии, пока она не готова — оригинал. С меткой версии для кэша. */
    public static function for(Media $media, ?string $conversion = null): string
    {
        return self::url($media, $conversion).'?v='.($media->updated_at?->timestamp ?? 0);
    }

    /** Конверсии вниз и оригинал как самый широкий кандидат. */
    public static function srcset(Media $media): string
    {
        return collect([320, 640, 960])
            ->map(fn ($w) => self::for($media, "w{$w}")." {$w}w")
            ->push(self::for($media).' '.PhotoIngest::MAX_DIMENSION.'w')
            ->implode(', ');
    }

    private static function url(Media $media, ?string $conversion): string
    {
        if ($conversion === null) {
            return $media->getUrl();
        }
        try {
            return $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : $media->getUrl();
        } catch (Throwable) {
            return $media->getUrl();
        }
    }
}
