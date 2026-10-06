<?php

namespace App\Media;

use Illuminate\Support\Collection;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Фотографии и документы у машины: коллекции `photos` (публичный диск, Caddy
 * отдаёт сам) и `papers` (закрытый диск: ПТС и договоры наружу только через
 * /files с проверкой прав), конверсии под srcset. Скрытый кадр — `Hidden::is`,
 * главный — первый по порядку. У предложения с ТС парковки файлы — её (`SaleMedia`).
 */
trait HasPhotos
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        // Оригиналы на media (HDD, в бэкапе), сжатые версии на hot (SSD, пересчитываемые).
        $this->addMediaCollection('photos')->useDisk('media')->storeConversionsOnDisk('hot');
        $this->addMediaCollection('papers')->useDisk('private');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media?->collection_name === 'papers') {
            return;
        }
        // Верхний размер — сам оригинал (1600 px из PhotoIngest), конверсии только вниз.
        foreach ([320, 640, 960] as $width) {
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
        return $this->photos()->reject(fn (Media $m) => $this->hides($m))->values();
    }

    /** Скрыт ли кадр здесь. У ТС парковки скрытых нет: глаз — это показ в продаже, а не в деле. */
    protected function hides(Media $media): bool
    {
        return Hidden::is($media);
    }

    public function mainPhoto(): ?Media
    {
        return $this->visiblePhotos()->first();
    }

    /**
     * Такой файл уже есть в медиатеке. Отпечатков у кадра два: `sha` — исходника до ужатия, `sent_sha` — тех
     * байтов, что ушли во вложении письма. Без второго наш же кадр, вернувшийся с письмом, лёг бы вторым разом.
     */
    public function hasFile(string $sha): bool
    {
        // `page_sha` — лист, с которого вырезан кадр (`PhotoIngest`): тот же лист второй раз не режется.
        return $this->media()->where(fn ($q) => $q->where('custom_properties->sha', $sha)->orWhere('custom_properties->sent_sha', $sha)->orWhere('custom_properties->page_sha', $sha))->exists();
    }

    /** @return Collection<int, Media> */
    public function papers(): Collection
    {
        return $this->getMedia('papers');
    }
}
