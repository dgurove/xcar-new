<?php

namespace App\Media\Actions;

use App\Media\PhotoIngest;
use App\Media\Watermark;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Поворот снимка на $turns четвертей по часовой (быстрые нажатия подряд копятся на клиенте и приходят одним запросом:
 * один проход перекодирования вместо трёх) — на месте, вместе со всеми
 * готовыми конверсиями. Пересборка ушла бы в очередь, и до прихода воркера
 * в галерее висел бы прежний кадр; thumb 400×300 после поворота становится
 * 300×400 — плитка режет его под свою рамку, а следующая пересборка
 * (WarmPhotos) вернёт правильный. `touch()` двигает updated_at, MediaUrl
 * подставляет его в `?v=` — браузер берёт новый файл. Чистая копия кадра под
 * водяным знаком (Watermark::cleanPath) крутится вместе с ним.
 */
final class RotatePhoto
{
    public function __invoke(Media $media, int $turns = 1): void
    {
        $angle = (($turns % 4) + 4) % 4 * 90;
        if ($angle === 0 || ! $this->turn($media->getPath(), $angle)) {
            return;
        }
        foreach (array_keys(array_filter((array) $media->generated_conversions)) as $conversion) {
            $this->turn($media->getPath((string) $conversion), $angle);
        }
        $this->turn(Watermark::cleanPath($media), $angle);
        $media->size = (int) filesize($media->getPath());
        $media->touch();
    }

    /** Сколько четвертей прислал клиент: 1–3, иначе одна. */
    public static function turns(Request $request): int
    {
        $turns = (int) $request->input('turns', 1);

        return $turns >= 1 && $turns <= 3 ? $turns : 1;
    }

    private function turn(string $path, int $angle): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return false;
        }
        // GD крутит против часовой: отрицательный угол — по часовой.
        $rotated = imagerotate($image, -$angle, 0);
        if ($rotated === false) {
            return false;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => imagepng($rotated, $path),
            'jpg', 'jpeg' => imagejpeg($rotated, $path, 92),
            // с тем же качеством, что при приёме: 90 кодировалось дольше и раздувало файл без видимой разницы
            default => imagewebp($rotated, $path, PhotoIngest::QUALITY),
        };
    }
}
