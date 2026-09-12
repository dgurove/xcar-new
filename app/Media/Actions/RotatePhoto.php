<?php

namespace App\Media\Actions;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Поворот снимка на четверть по часовой — на месте, вместе со всеми
 * готовыми конверсиями. Пересборка ушла бы в очередь, и до прихода воркера
 * в галерее висел бы прежний кадр; thumb 400×300 после поворота становится
 * 300×400 — плитка режет его под свою рамку, а следующая пересборка
 * (WarmPhotos) вернёт правильный. `touch()` двигает updated_at, MediaUrl
 * подставляет его в `?v=` — браузер берёт новый файл.
 */
final class RotatePhoto
{
    public function __invoke(Media $media, bool $clockwise = true): void
    {
        if (! $this->turn($media->getPath(), $clockwise)) {
            return;
        }
        foreach (array_keys(array_filter((array) $media->generated_conversions)) as $conversion) {
            $this->turn($media->getPath((string) $conversion), $clockwise);
        }
        $media->size = (int) filesize($media->getPath());
        $media->touch();
    }

    private function turn(string $path, bool $clockwise): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return false;
        }
        // GD крутит против часовой: отрицательный угол — по часовой.
        $rotated = imagerotate($image, $clockwise ? -90 : 90, 0);
        if ($rotated === false) {
            return false;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => imagepng($rotated, $path),
            'jpg', 'jpeg' => imagejpeg($rotated, $path, 92),
            default => imagewebp($rotated, $path, 90),
        };
    }
}
