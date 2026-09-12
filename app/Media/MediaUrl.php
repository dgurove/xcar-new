<?php

namespace App\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class MediaUrl
{
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
