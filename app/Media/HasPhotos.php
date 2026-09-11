<?php

namespace App\Media;

use Illuminate\Support\Collection;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Фотографии и документы у машины: коллекции `photos` и `papers`, конверсии
 * под srcset. Скрытый кадр — custom property `hidden`, главный — первый по
 * порядку.
 */
trait HasPhotos
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('media');
        $this->addMediaCollection('papers')->useDisk('media');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media?->collection_name === 'papers') {
            return;
        }
        foreach ([320, 640, 960, 1440] as $width) {
            $this->addMediaConversion("w{$width}")->width($width)->format('webp')->queued();
        }
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 400, 300)->format('webp')->queued();
    }

    /** @return Collection<int, Media> */
    public function photos(): Collection
    {
        return $this->getMedia('photos');
    }

    /** @return Collection<int, Media> */
    public function visiblePhotos(): Collection
    {
        return $this->photos()->reject(fn (Media $m) => $m->getCustomProperty('hidden', false))->values();
    }

    public function mainPhoto(): ?Media
    {
        return $this->visiblePhotos()->first();
    }

    /** @return Collection<int, Media> */
    public function papers(): Collection
    {
        return $this->getMedia('papers');
    }
}
