<?php

namespace App\Media;

/** Реестр чужих водяных знаков (resources/watermarks): имя знака → название площадки для людей. */
final class Watermarks
{
    /** @return array<string, string> имя знака → название площадки, по алфавиту названий */
    public static function all(): array
    {
        $all = [];
        foreach (glob(resource_path('watermarks/*.json')) ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $all[$name] = self::title($name);
        }
        asort($all);

        return $all;
    }

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
