<?php

namespace App\Offers\Share;

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
 * Объект — оффер или машина закупки (`Subject`).
 */
final class SharePdf
{
    public const MAX = 1280;

    public const QUALITY = 72;

    public const DISK = 'cache';

    public function __construct(private PdfWriter $writer) {}

    /** @param  list<int>  $mediaIds */
    public function build(Subject $subject, array $mediaIds, bool $watermark): string
    {
        $media = $subject->model->visiblePhotos()->filter(fn (Media $m) => in_array($m->id, $mediaIds, true))->values();
        if ($media->isEmpty()) {
            throw new RuntimeException('Не выбрано ни одной фотографии');
        }
        $key = substr(sha1($subject->cacheDir().':'.$media->pluck('id')->implode(',').':'.(int) $watermark.':'.$media->max('updated_at')), 0, 16);
        // Готовый PDF — кэш: пересобирается из фото, storage:gc стирает старые.
        $path = $subject->cacheDir()."/{$key}.pdf";
        $disk = Storage::disk(self::DISK);
        if ($disk->exists($path)) {
            return $disk->path($path);
        }

        $photos = [];
        foreach ($media as $m) {
            try {
                // Кадр для PDF — без знака: полосу рисует сам PDF, резкую на любом увеличении.
                $file = $this->jpeg($subject, $m, false);
                [$width, $height] = getimagesize($file);
                $photos[] = ['path' => $file, 'width' => $width, 'height' => $height];
            } catch (Throwable $e) {
                Log::warning('Фотография не попала в PDF', ['media' => $m->id, 'error' => $e->getMessage()]);
            }
        }
        $disk->put($path, $this->writer->write($photos, $watermark ? $this->stamp($subject) : null));
        $this->prune($subject->cacheDir(), $path);

        return $disk->path($path);
    }

    /**
     * Кадр для отправки фотографиями: базовый JPEG 1280 px, знак — та же полоса снизу, что в PDF, но впечённая в
     * картинку. Кэш рядом с PDF в `photos/`: его же берёт PDF, `prune` подпапку не видит, чистит `storage:gc`.
     */
    public function jpeg(Subject $subject, Media $m, bool $watermark): string
    {
        $path = $subject->cacheDir().'/photos/'.$m->id.'-'.(int) $watermark.'-'.$m->updated_at?->timestamp.'.jpg';
        $disk = Storage::disk(self::DISK);
        if ($disk->exists($path)) {
            return $disk->path($path);
        }
        $disk->makeDirectory(dirname($path));
        $tmp = $disk->path($path).'.'.getmypid().'.tmp.jpg';
        try {
            if ($watermark) {
                $this->stampJpeg($this->jpeg($subject, $m, false), $tmp, $this->stamp($subject));
            } else {
                Image::useImageDriver('gd')->loadFile($m->getPath())->fit(Fit::Max, self::MAX, self::MAX)->quality(self::QUALITY)->save($tmp);
            }
            rename($tmp, $disk->path($path));
        } finally {
            @unlink($tmp);
        }

        return $disk->path($path);
    }

    public function stamp(Subject $subject): string
    {
        return implode('   ', array_filter([$subject->label, $subject->date?->format('d.m.Y'), 'xcar.ru']));
    }

    /** Полоса как у `PdfWriter`: 3,8 % высоты (10–20 pt при 150 dpi), тёмная с прозрачностью 0,42, белый текст по центру. */
    private function stampJpeg(string $from, string $to, string $text): void
    {
        $image = @imagecreatefromjpeg($from);
        if ($image === false) {
            throw new RuntimeException('Кадр не читается');
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = 150 / 72;
        $bar = (int) round(min(20 * $scale, max(10 * $scale, $h * 0.038)));
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, $h - $bar, $w, $h, imagecolorallocatealpha($image, 31, 31, 31, (int) round(127 * (1 - 0.42))));
        $font = resource_path('fonts/pdf/Onest-Regular.ttf');
        $size = $bar * 0.55 * 0.75; // кегль в пунктах: GD считает при 96 dpi
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = $box[2] - $box[0];
        $capH = -$box[7];
        $left = (int) max(round($bar * 0.6), ($w - $textW) / 2);
        $baseline = (int) round($h - ($bar - $capH) / 2);
        imagettftext($image, $size, 0, $left, $baseline, imagecolorallocate($image, 255, 255, 255), $font, $text);
        imageinterlace($image, false);
        imagejpeg($image, $to, self::QUALITY);
    }

    private function prune(string $dir, string $keep): void
    {
        $disk = Storage::disk(self::DISK);
        $files = collect($disk->files($dir))->filter(fn ($f) => $f !== $keep)->sortByDesc(fn ($f) => $disk->lastModified($f))->values();
        foreach ($files->slice(5) as $f) {
            $disk->delete($f);
        }
    }
}
