<?php

namespace App\Media;

/** Реестр чужих водяных знаков (resources/watermarks): имя знака → название площадки для людей. */
final class Watermarks
{
    public static function title(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $file = resource_path("watermarks/{$name}.json");
        $title = is_file($file) ? (json_decode((string) file_get_contents($file), true)['title'] ?? null) : null;

        return is_string($title) ? $title : $name;
    }
}
