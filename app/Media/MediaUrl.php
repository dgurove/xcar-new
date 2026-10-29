<?php

namespace App\Media;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGeneratorFactory;
use Throwable;
use WeakMap;

final class MediaUrl
{
    /**
     * Телефон с плотным экраном (3x) на кадре во всю ширину просил бы ~1170 px и брал оригинал: браузеру говорим «320 px»,
     * он выбирает w960 — на экране не отличить, а весит вдвое-втрое меньше и не забивает память телефона.
     */
    private const PHONE_DENSE = '(max-width: 639.98px) and (min-resolution: 2.5dppx) 320px';

    /** @var array<string, Conversion> конверсия по модели, коллекции и имени — одна на процесс */
    private static array $conversions = [];

    /**
     * Адреса кадра, пока жив сам объект: плитка спрашивает w640 для `src` и его же в `srcset`, страница — тот же кадр
     * в ленте и просмотре. Ключ — объект (WeakMap): ушла модель — ушли и адреса, память воркера Octane не растёт.
     *
     * @var WeakMap<Media, array<string, string>>|null
     */
    private static ?WeakMap $memo = null;

    /** Генератор адресов последнего кадра: srcset спрашивает его конверсии подряд. Не в WeakMap — он держит сам кадр. */
    private static ?Media $genOf = null;

    private static ?UrlGenerator $gen = null;

    /** sizes для srcset: если на телефоне кадр во всю ширину (последнее значение в vw) — с потолком для 3x. */
    public static function sizes(string $sizes): string
    {
        $phone = trim((string) last(explode(',', $sizes)));

        return str_contains($phone, 'vw') && ! str_contains($sizes, 'dppx') ? self::PHONE_DENSE.', '.$sizes : $sizes;
    }

    /** Адрес конверсии, пока она не готова — оригинал. С меткой версии для кэша. */
    public static function for(Media $media, ?string $conversion = null): string
    {
        self::$memo ??= new WeakMap;
        $urls = self::$memo[$media] ?? [];
        // Версия — в ключе: повернули кадр в этом же запросе — адрес новый. Ключ — сырое `updated_at`: разбор даты Carbon
        // на каждый адрес стоил больше самого адреса.
        $key = ($conversion ?? '').'@'.($media->getAttributes()['updated_at'] ?? '');
        if (! isset($urls[$key])) {
            $v = $media->updated_at?->timestamp ?? 0;
            $urls[$key] = self::url($media, $conversion).'?v='.$v;
            self::$memo[$media] = $urls;
        }

        return $urls[$key];
    }

    /** @var WeakMap<Model, array<string, array{0: mixed, 1: ?string}>>|null адрес первого файла коллекции у модели */
    private static ?WeakMap $firsts = null;

    /**
     * Адрес первого файла коллекции (логотип вендора, аватар человека) — раз на модель, пока не сменился её список файлов
     * (29.10.2026): `getFirstMedia` на каждый вызов фильтрует и сортирует коллекцию, а логотип и аватар стоят в строке
     * таблицы по два раза — пятая часть страницы «Без закупочной».
     */
    public static function first(HasMedia&Model $model, string $collection, ?string $conversion = null): ?string
    {
        self::$firsts ??= new WeakMap;
        $key = $collection.'|'.$conversion;
        $memo = self::$firsts[$model] ?? [];
        $loaded = $model->relationLoaded('media') ? $model->getRelation('media') : null;
        if ($loaded !== null && isset($memo[$key]) && $memo[$key][0] === $loaded) {
            return $memo[$key][1];
        }
        $media = $model->getFirstMedia($collection);
        $url = $media ? self::for($media, $conversion) : null;
        $memo[$key] = [$model->relationLoaded('media') ? $model->getRelation('media') : null, $url];
        self::$firsts[$model] = $memo;

        return $url;
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
            if (! $media->hasGeneratedConversion($conversion)) {
                return $media->getUrl();
            }
            // Генератор (контейнер, генератор путей) — раз на кадр.
            if (self::$genOf !== $media) {
                self::$gen = UrlGeneratorFactory::createForMedia($media);
                self::$genOf = $media;
            }

            return self::$gen->setConversion(self::conversion($media, $conversion))->getUrl();
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
