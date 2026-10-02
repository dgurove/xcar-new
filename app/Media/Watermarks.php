<?php

namespace App\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Реестр чужих водяных знаков: встроенные (resources/watermarks — вектор, Мигторг) и собранные по фото рамкой в шторке
 * «Водяной знак» (`private/watermarks` — PNG формы). Названия и выключенные — `settings.json` там же
 * ({"имя": {"title": …, "off": true}}), его читает и скрипт `unmark`: выключенный знак не ищется.
 */
final class Watermarks
{
    /** Каталоги знаков для скрипта, через «:». */
    public static function dirs(): string
    {
        return resource_path('watermarks').PATH_SEPARATOR.self::learnedDir();
    }

    public static function learnedDir(): string
    {
        $dir = Storage::disk('private')->path('watermarks');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** @return array<string, array{title: string, off: bool, tuned: int, learned: bool, image: string}> по алфавиту названий */
    public static function registry(): array
    {
        $over = self::settings();
        $all = [];
        foreach ([resource_path('watermarks') => false, self::learnedDir() => true] as $dir => $learned) {
            foreach (glob("{$dir}/*.json") ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if ($name === 'settings') {
                    continue;
                }
                $conf = json_decode((string) file_get_contents($file), true) ?: [];
                $all[$name] = [
                    'title' => (string) ($over[$name]['title'] ?? $conf['title'] ?? $name),
                    'off' => (bool) ($over[$name]['off'] ?? false),
                    'tuned' => (int) ($over[$name]['tuned'] ?? 0),
                    'learned' => $learned,
                    'image' => $dir.'/'.($conf['png'] ?? $conf['svg'] ?? ''),
                ];
            }
        }
        uasort($all, fn ($a, $b) => strcasecmp($a['title'], $b['title']));

        return $all;
    }

    /** @return array<string, string> включённые: имя → название (шторка «Водяной знак») */
    public static function all(): array
    {
        return collect(self::registry())->reject(fn ($m) => $m['off'])->map(fn ($m) => $m['title'])->all();
    }

    public static function title(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return self::registry()[$name]['title'] ?? $name;
    }

    /** Название и «выключен» поверх файла знака. */
    public static function set(string $name, array $values): void
    {
        $over = self::settings();
        $over[$name] = array_merge($over[$name] ?? [], $values);
        file_put_contents(self::learnedDir().'/settings.json', json_encode($over, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
    }

    /** Собранный по фото знак — стереть совсем (встроенный только выключается). */
    public static function forget(string $name): void
    {
        $dir = self::learnedDir();
        $conf = json_decode((string) @file_get_contents("{$dir}/{$name}.json"), true) ?: [];
        @unlink("{$dir}/{$name}.json");
        if (! empty($conf['png'])) {
            @unlink("{$dir}/".basename($conf['png']));
        }
        $over = self::settings();
        unset($over[$name]);
        file_put_contents("{$dir}/settings.json", json_encode((object) $over, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
    }

    private static function settings(): array
    {
        $file = self::learnedDir().'/settings.json';

        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }
}
