<?php

namespace App\Media\Actions;

use App\Media\Watermark;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Водяной знак в оригинал кадра: чистая копия уезжает на закрытый диск
 * (Watermark::cleanPath), конверсии стираются — страницы отдают оригинал, пока
 * очередь считает их заново уже со знаком. `touch()` двигает `?v=`, браузер
 * берёт новый файл. Повторный вызов безвреден: клеймо ставится один раз.
 * Для только что добавленного кадра конверсии ставит сам spatie — `conversions: false`.
 */
final class StampPhoto
{
    public function __construct(private FileManipulator $files, private CoolPhotos $cool) {}

    public function __invoke(Media $media, bool $conversions = true): void
    {
        if ($media->getCustomProperty('watermarked', false) || ! is_file($media->getPath())) {
            return;
        }
        $clean = Watermark::cleanPath($media->id);
        if (! is_file($clean)) {
            @mkdir(dirname($clean), 0775, true);
            copy($media->getPath(), $clean);
        }
        ($this->cool)([$media]);
        Watermark::apply($media->getPath(), $media->id);
        $media->setCustomProperty('watermarked', true);
        $media->size = (int) filesize($media->getPath());
        $media->save();
        if ($conversions) {
            $this->files->createDerivedFiles($media);
        }
    }
}
