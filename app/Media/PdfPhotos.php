<?php

namespace App\Media;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * PDF, в котором лежат фотографии машины, — по кадру на страницу (07.10.2026, AUT-26-911955: страховая прислала
 * «images (2).pdf», 15 страниц по снимку 3000×2250, а предложение осталось без фото). Такой PDF — не документ, а
 * упаковка: кадры достаются как есть (`pdfimages`, JPEG без пересжатия) и идут к фото обычным приёмом.
 *
 * Отличить от скана документа: на каждой странице ровно один крупный цветной снимок, лежащий горизонтально, и почти
 * нет текста (бывает имя файла снимка). Скан СТС, акта или заявки — портретный лист, часто серый, — остаётся
 * документом.
 */
final class PdfPhotos
{
    /** Меньше — не снимок, а значок, подпись или логотип на листе. */
    private const MIN_SIDE = 800;

    private const MAX_PAGES = 120;

    /** Текста на страницу — не больше подписи в одну строку (имя файла снимка). */
    private const MAX_TEXT_PER_PAGE = 80;

    /** @return list<array{name: string, contents: string}>|null кадры по порядку страниц; null — это не PDF со снимками */
    public static function extract(string $contents, string $name): ?array
    {
        if (! str_starts_with($contents, '%PDF')) {
            return null;
        }
        $dir = sys_get_temp_dir().'/pdf-photos-'.Str::random(12);
        File::ensureDirectoryExists($dir);
        try {
            $path = $dir.'/source.pdf';
            File::put($path, $contents);
            $images = self::photosOf($path);
            if ($images === null) {
                return null;
            }
            $run = Process::timeout(120)->run(['pdfimages', '-j', '-png', $path, $dir.'/p']);
            if (! $run->successful()) {
                return null;
            }
            $base = pathinfo($name, PATHINFO_FILENAME) ?: 'foto';
            $out = [];
            foreach ($images as $i => $num) {
                $file = collect(glob(sprintf('%s/p-%03d.*', $dir, $num)) ?: [])->first();
                if (! $file) {
                    return null;
                }
                $out[] = ['name' => $base.'-'.($i + 1).'.'.pathinfo($file, PATHINFO_EXTENSION), 'contents' => File::get($file)];
            }

            return $out;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Без извлечения: снимки ли это (для «Завести», где документы кладутся сразу, а снимки — задачей). */
    public static function looksLike(string $contents): bool
    {
        if (! str_starts_with($contents, '%PDF')) {
            return false;
        }
        $path = tempnam(sys_get_temp_dir(), 'pdfp');
        try {
            File::put($path, $contents);

            return self::photosOf($path) !== null;
        } finally {
            @unlink($path);
        }
    }

    /** @return list<int>|null номера снимков (`num` у pdfimages) по страницам; null — не все страницы такие */
    private static function photosOf(string $path): ?array
    {
        $list = Process::timeout(30)->run(['pdfimages', '-list', $path]);
        if (! $list->successful()) {
            return null;
        }
        $byPage = [];
        foreach (array_slice(explode("\n", trim($list->output())), 2) as $line) {
            $c = preg_split('/\s+/', trim($line));
            // page num type width height color comp ...
            if (count($c) < 7 || $c[2] !== 'image') {
                continue;
            }
            [$page, $num, , $w, $h, $color] = [(int) $c[0], (int) $c[1], $c[2], (int) $c[3], (int) $c[4], $c[5]];
            if (min($w, $h) >= self::MIN_SIDE) {
                $byPage[$page][] = ['num' => $num, 'landscape' => $w > $h, 'color' => in_array($color, ['rgb', 'icc', 'cmyk', 'index'], true)];
            }
        }
        $pages = count($byPage);
        if ($pages === 0 || $pages > self::MAX_PAGES || array_keys($byPage) !== range(1, $pages)) {
            return null;
        }
        foreach ($byPage as $images) {
            if (count($images) !== 1 || ! $images[0]['landscape'] || ! $images[0]['color']) {
                return null;
            }
        }
        $text = Process::timeout(30)->run(['pdftotext', '-enc', 'UTF-8', $path, '-']);
        if (mb_strlen(preg_replace('/\s+/u', '', $text->output())) > self::MAX_TEXT_PER_PAGE * $pages) {
            return null;
        }

        return array_values(array_map(fn ($images) => $images[0]['num'], $byPage));
    }
}
