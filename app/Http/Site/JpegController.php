<?php

namespace App\Http\Site;

use App\Media\WebpJpeg;

/**
 * Кадр медиатеки JPEG-ом для «Скачать» в просмотре фото (15.11.2026, владелец: «всех бесит этот webp»): `/media` и `/hot`
 * раздаёт Caddy с диска как есть (webp), а скачивают через `/jpg/media/…` — тот же кадр JPEG (`WebpJpeg`). Кадры открытые,
 * как и сами `/media`; путь — только внутри этих двух дисков.
 */
final class JpegController
{
    public function show(string $path)
    {
        $file = realpath(storage_path('app/'.$path));
        $inside = fn (string $root) => ($real = realpath(storage_path('app/'.$root))) && str_starts_with((string) $file, $real.'/');
        abort_unless($file && is_file($file) && ($inside('media') || $inside('hot')), 404);
        $jpeg = WebpJpeg::of($file, 'p'.$path);
        abort_unless($jpeg, 404);

        return response()->file($jpeg, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode(WebpJpeg::name(basename($file))),
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
