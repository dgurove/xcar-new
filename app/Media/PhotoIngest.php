<?php

namespace App\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Приём фотографии. Оригинал не хранится: кадр с телефона ужимается до 1600 px
 * в webp (2 МБ → ~200 КБ) и только потом попадает в медиатеку.
 */
final class PhotoIngest
{
    public const MAX_DIMENSION = 1600;
    public const QUALITY = 80;
    public const MAX_SOURCE_PIXELS = 40_000_000;

    public function fromUpload(HasMedia $model, string $collection, UploadedFile $file, array $properties = [], int $max = self::MAX_DIMENSION): Media
    {
        return $this->add($model, $collection, $file->getRealPath(), $file->getClientOriginalName(), $properties, $max);
    }

    public function fromString(HasMedia $model, string $collection, string $contents, string $name, array $properties = []): Media
    {
        $temp = tempnam(sys_get_temp_dir(), 'kadr-');
        file_put_contents($temp, $contents);

        return $this->add($model, $collection, $temp, $name, $properties);
    }

    public function add(HasMedia $model, string $collection, string $path, string $name, array $properties = [], int $max = self::MAX_DIMENSION): Media
    {
        $webp = null;
        try {
            $this->checkSize($path);
            $webp = $this->shrink($path, $max);

            return $model->addMedia($webp)
                ->usingFileName($this->fileName($name))
                ->usingName(pathinfo($name, PATHINFO_FILENAME))
                ->withCustomProperties($properties)
                ->toMediaCollection($collection);
        } finally {
            @unlink($path);
            if ($webp && is_file($webp)) {
                @unlink($webp);
            }
        }
    }

    private function checkSize(string $path): void
    {
        $size = @getimagesize($path);
        if ($size === false) {
            throw new RuntimeException('Файл не читается как изображение');
        }
        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw new RuntimeException("Кадр слишком большой: {$size[0]}×{$size[1]}");
        }
    }

    /** Тот же приём без медиатеки: путь к ужатому webp во временном файле; удалить — забота вызвавшего. */
    public function shrink(string $path, int $max = self::MAX_DIMENSION): string
    {
        $webp = $path.'.webp';
        try {
            Image::load($path)->fit(Fit::Max, $max, $max)->format('webp')->quality(self::QUALITY)->save($webp);
        } catch (Throwable $e) {
            throw new RuntimeException('Кадр не пережался: '.$e->getMessage(), previous: $e);
        }
        if (! is_file($webp) || filesize($webp) === 0) {
            throw new RuntimeException('Кадр не пережался');
        }

        return $webp;
    }

    private function fileName(string $original): string
    {
        $base = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?? '', '-.');

        return ($base === '' ? 'kadr-'.Str::random(8) : $base).'.webp';
    }
}
