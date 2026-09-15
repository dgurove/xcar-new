<?php

namespace App\Media\Actions;

use App\Media\Watermark;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Снять водяной знак: чистая копия возвращается на место оригинала, конверсии считаются заново. */
final class UnstampPhoto
{
    public function __construct(private FileManipulator $files, private CoolPhotos $cool) {}

    public function __invoke(Media $media): void
    {
        $clean = Watermark::cleanPath($media);
        if (! $media->getCustomProperty('watermarked', false) || ! is_file($clean)) {
            return;
        }
        ($this->cool)([$media]);
        copy($clean, $media->getPath());
        @unlink($clean);
        $media->setCustomProperty('watermarked', false);
        $media->size = (int) filesize($media->getPath());
        $media->save();
        $this->files->createDerivedFiles($media);
    }
}
