<?php

namespace App\Media;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
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

    /** 48 МП с телефонов Samsung проходят; GD держит ~4 байта на пиксель, лимит памяти 512M. */
    public const MAX_SOURCE_PIXELS = 64_000_000;

    public function fromUpload(HasMedia $model, string $collection, UploadedFile $file, array $properties = [], int $max = self::MAX_DIMENSION): Media
    {
        return $this->add($model, $collection, $file->getRealPath(), $file->getClientOriginalName(), $properties, $max);
    }

    /** Загрузка с телефона: кадр ужат до отправки, отпечаток исходника приходит полем sha. */
    public function fromPhone(HasMedia $model, string $collection, Request $request, int $max = self::MAX_DIMENSION): Media
    {
        $sha = $request->input('sha');
        $properties = is_string($sha) && preg_match('/^[a-f0-9]{64}$/', $sha) ? ['sha' => $sha] : [];

        return $this->fromUpload($model, $collection, $request->file('file'), $properties, $max);
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
            $path = $this->fromHeic($path);
            $this->checkSize($path);
            // Отпечаток исходника — чтобы тот же файл (из письма, с телефона, из архива) не лёг второй раз.
            $properties += ['sha' => hash_file('sha256', $path)];
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

    /**
     * HEIC с айфона (папки Carcade полны ими): GD его не читает, перегоняем
     * в JPEG скриптом heic2jpg из образа (deploy/bin). Узнаём по сигнатуре
     * ftyp…, не по имени — из архивов и с телефона имя бывает любым.
     */
    private function fromHeic(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 4, 8);
        if (! preg_match('/^ftyp(heic|heix|hevc|hevx|heim|heis|mif1|msf1)/', $head)) {
            return $path;
        }
        $base = tempnam(sys_get_temp_dir(), 'heic-');
        $jpg = $base.'.jpg';
        $result = Process::timeout(120)->run(['heic2jpg', $path, $jpg]);
        @unlink($base);
        if (! $result->successful() || ! is_file($jpg) || filesize($jpg) === 0) {
            @unlink($jpg);
            throw new RuntimeException('HEIC не перекодировался: '.trim($result->errorOutput() ?: $result->output()) ?: 'нет heic2jpg');
        }
        @unlink($path);

        return $jpg;
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
