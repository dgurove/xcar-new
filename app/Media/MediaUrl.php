<?php

namespace App\Media;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGeneratorFactory;
use Throwable;

final class MediaUrl
{
    /**
     * Телефон с плотным экраном (3x) на кадре во всю ширину просил бы ~1170 px и брал оригинал: браузеру говорим «320 px»,
     * он выбирает w960 — на экране не отличить, а весит вдвое-втрое меньше и не забивает память телефона.
     */
    private const PHONE_DENSE = '(max-width: 639.98px) and (min-resolution: 2.5dppx) 320px';

    /** @var array<string, Conversion> конверсия по модели, коллекции и имени — одна на процесс */
    private static array $conversions = [];

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
            return $media->hasGeneratedConversion($conversion)
                ? UrlGeneratorFactory::createForMedia($media)->setConversion(self::conversion($media, $conversion))->getUrl()
                : $media->getUrl();
        } catch (Throwable) {
            return $media->getUrl();
        }
    }

    /**
     * `getUrl($conversion)` на каждый адрес заново заводит модель и все её конверсии (~1,2 мс): каталог в 212 плиток по
     * шесть кадров и четыре адреса на кадр собирался 7 с из 8 и под нагрузкой упирался в 30 с (16.10.2026). Конверсии
     * зависят только от модели и коллекции (`registerMediaConversions`) — собираем их раз.
     */
    private static function conversion(Media $media, string $name): Conversion
    {
        return self::$conversions[$media->model_type.'|'.$media->collection_name.'|'.$name]
            ??= ConversionCollection::createForMedia($media)->getByName($name);
    }
}
