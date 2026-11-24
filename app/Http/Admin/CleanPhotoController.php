<?php

namespace App\Http\Admin;

use App\Media\Thumb;
use App\Media\Watermark;
use App\Media\WebpJpeg;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадр без водяного знака (кому — `Media\CleanFor`: сотрудник, менеджер после своей оценки) (24.11.2026, владелец: «знаки — для менеджеров, пока не дадут оценку; зачем они в
 * админке»). Знак впечён в сам файл (`StampPhoto`), чистая копия — на закрытом диске (`Watermark::cleanPath`): `MediaUrl` таким
 * ведёт сюда вместо `/media/…`. Размеры — как конверсии (`w320|640|960`, считаются раз и лежат в `cache`), без размера — копия
 * целиком, `jpg` — она же JPEG-ом для «Скачать» в просмотре. Знака нет или копии нет — файл как есть.
 */
final class CleanPhotoController
{
    public function show(Media $media, ?string $size = null)
    {
        abort_unless(\App\Media\CleanFor::allows(auth()->user(), $media), 404);
        $clean = $media->getCustomProperty('watermarked', false) ? Watermark::cleanPath($media) : null;
        $file = $clean && is_file($clean) ? $clean : $media->getPath();
        abort_unless(is_file($file), 404);
        $v = $media->updated_at?->timestamp ?? 0;
        if ($size === 'jpg') {
            $jpeg = WebpJpeg::of($file, "clean-{$media->id}-{$v}");
            abort_unless($jpeg, 404);

            return response()->file($jpeg, [
                'Content-Type' => 'image/jpeg',
                'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode(WebpJpeg::name($media->file_name)),
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }
        if ($size !== null) {
            $file = Thumb::of($file, false, "clean-{$media->id}-{$size}-{$v}", (int) $size) ?? $file;
        }

        return response()->file($file, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
