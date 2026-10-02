<?php

namespace App\Media\Actions;

use App\Media\PhotoIngest;
use App\Media\Unmark;
use App\Media\Watermark;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Чужой знак площадки с кадра, который уже лежит в медиатеке (новые снимает сам `PhotoIngest`). Кадр со знаком уезжает
 * на закрытый диск (`markedPath`), откат — `undo`. Если на кадре наш знак (запрет шеринга), снимается с чистой копии,
 * и наш знак кладётся заново поверх. Конверсии считаются заново, `save()` двигает `?v=`.
 */
final class UnmarkPhoto
{
    /** `unmarked` у кадра, заменённого своим файлом. */
    public const MANUAL = 'manual';

    public function __construct(private Unmark $unmark, private FileManipulator $files, private CoolPhotos $cool) {}

    public static function markedPath(Media $media): string
    {
        return Storage::disk('private')->path("marked/{$media->id}.".strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'webp'));
    }

    /** Имя снятого знака или null — знака нет, кадр не тронут. */
    public function __invoke(Media $media): ?string
    {
        if ($media->getCustomProperty('unmarked')) {
            return null;
        }
        $source = $this->source($media);
        if (! is_file($source)) {
            return null;
        }
        $clean = ($this->unmark)($source);
        if (! $clean) {
            return null;
        }
        try {
            $backup = self::markedPath($media);
            @mkdir(dirname($backup), 0775, true);
            if (! copy($source, $backup)) {
                throw new RuntimeException("Кадр {$media->id}: копия со знаком не записалась");
            }
            $this->encode($clean['path'], $source);
        } finally {
            @unlink($clean['path']);
        }
        $media->setCustomProperty('unmarked', $clean['mark']);
        $this->refresh($media);

        return $clean['mark'];
    }

    /**
     * Свой файл вместо кадра (почистили знак в фотошопе): тот же media — порядок, «скрыт», главный кадр, наш знак.
     * Кадр со знаком уходит в `markedPath`, если его там ещё нет: «Вернуть со знаком» работает и после замены.
     */
    public function replace(Media $media, string $file, PhotoIngest $ingest): void
    {
        $source = $this->source($media);
        $backup = self::markedPath($media);
        if (! is_file($backup)) {
            @mkdir(dirname($backup), 0775, true);
            if (! copy($source, $backup)) {
                throw new RuntimeException("Кадр {$media->id}: копия со знаком не записалась");
            }
        }
        $webp = $ingest->shrink($file);
        try {
            $this->encode($webp, $source);
        } finally {
            @unlink($webp);
        }
        $media->setCustomProperty('unmarked', self::MANUAL);
        $this->refresh($media);
    }

    /** Вернуть кадр со знаком площадки. */
    public function undo(Media $media): bool
    {
        $backup = self::markedPath($media);
        if (! $media->getCustomProperty('unmarked') || ! is_file($backup)) {
            return false;
        }
        copy($backup, $this->source($media));
        @unlink($backup);
        $media->forgetCustomProperty('unmarked');
        $this->refresh($media);

        return true;
    }

    /** Файл, с которого снимается знак: чистая копия, если поверх лежит наш знак, иначе сам оригинал. */
    private function source(Media $media): string
    {
        return $media->getCustomProperty('watermarked', false) ? Watermark::cleanPath($media) : $media->getPath();
    }

    /** В формат и размер прежнего файла: webp из PhotoIngest, jpg со старого сайта. */
    private function encode(string $image, string $target): void
    {
        $tmp = $target.'.tmp.'.(strtolower(pathinfo($target, PATHINFO_EXTENSION)) ?: 'webp');
        $ext = pathinfo($tmp, PATHINFO_EXTENSION);
        Image::load($image)->fit(Fit::Max, PhotoIngest::MAX_DIMENSION, PhotoIngest::MAX_DIMENSION)
            ->format($ext === 'jpeg' ? 'jpg' : $ext)->quality(PhotoIngest::QUALITY)->save($tmp);
        if (! is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new RuntimeException('Кадр без знака не записался');
        }
        rename($tmp, $target);
    }

    /** Оригинал под нашим знаком собирается заново из чистой копии, конверсии — с нуля. */
    private function refresh(Media $media): void
    {
        ($this->cool)([$media]);
        if ($media->getCustomProperty('watermarked', false)) {
            copy(Watermark::cleanPath($media), $media->getPath());
            Watermark::apply($media->getPath(), $media->id);
        }
        $media->size = (int) filesize($media->getPath());
        $media->save();
        $this->files->createDerivedFiles($media);
    }
}
