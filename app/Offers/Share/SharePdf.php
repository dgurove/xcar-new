<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * PDF для отправки в мессенджер: страница на фотографию, по размеру кадра,
 * базовый JPEG 1280 px. Готовый файл кэшируется на диске по набору фото и знаку.
 */
final class SharePdf
{
    public const MAX = 1280;

    public const QUALITY = 72;

    public function __construct(private PdfWriter $writer) {}

    /** @param  list<int>  $mediaIds */
    public function build(Offer $offer, array $mediaIds, bool $watermark): string
    {
        $media = $offer->visiblePhotos()->filter(fn (Media $m) => in_array($m->id, $mediaIds, true))->values();
        if ($media->isEmpty()) {
            throw new RuntimeException('Не выбрано ни одной фотографии');
        }
        $key = substr(sha1($offer->id.':'.$media->pluck('id')->implode(',').':'.(int) $watermark.':'.$media->max('updated_at')), 0, 16);
        $path = "share/{$offer->id}/{$key}.pdf";
        $disk = Storage::disk('private');
        if ($disk->exists($path)) {
            return $disk->path($path);
        }

        $work = storage_path('app/share/tmp/'.$key);
        @mkdir($work, 0775, true);
        try {
            $photos = [];
            foreach ($media as $m) {
                $target = "{$work}/photo-{$m->id}.jpg";
                try {
                    $image = Image::useImageDriver('gd')->loadFile($m->getPath())->fit(Fit::Max, self::MAX, self::MAX)->quality(self::QUALITY);
                    $photos[] = ['path' => $target, 'width' => $image->getWidth(), 'height' => $image->getHeight()];
                    $image->save($target);
                } catch (Throwable $e) {
                    Log::warning('Фотография не попала в PDF', ['media' => $m->id, 'error' => $e->getMessage()]);
                }
            }
            $stamp = $watermark ? implode(' · ', array_filter([(string) $offer->number, ($offer->published_at ?? $offer->created_at)?->format('d.m.Y'), 'xcar.ru'])) : null;
            $disk->put($path, $this->writer->write($photos, $stamp));
        } finally {
            foreach ((array) glob("{$work}/*") as $f) {
                @unlink($f);
            }
            @rmdir($work);
        }
        $this->prune($offer->id, $path);

        return $disk->path($path);
    }

    public function fileName(Offer $offer): string
    {
        $name = trim(preg_replace('~[\\\\/:*?"<>|%\x00-\x1F]+~u', ' ', $offer->number.' '.$offer->titleWithYear()));

        return (preg_replace('~\s+~u', ' ', $name) ?: 'xcar').'.pdf';
    }

    private function prune(int $offerId, string $keep): void
    {
        $disk = Storage::disk('private');
        $files = collect($disk->files("share/{$offerId}"))->filter(fn ($f) => $f !== $keep)->sortByDesc(fn ($f) => $disk->lastModified($f))->values();
        foreach ($files->slice(5) as $f) {
            $disk->delete($f);
        }
    }
}
