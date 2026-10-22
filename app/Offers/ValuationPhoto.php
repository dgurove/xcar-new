<?php

namespace App\Offers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * «Оценить → Из текста» фотографией (22.10.2026, владелец: скрин СМС Марины «AUT-26-979366 207000» — пусть номера убытка и
 * суммы берёт сам). Наш `ocr` (PaddleOCR, как «✨ Распознать») читает картинку в трёх масштабах одним вызовом — модели
 * грузятся один раз: на скрине с подчёркнутыми ссылками каждый масштаб ошибался в своей строке («AUT-2 -26-1027692740740»,
 * «9888000» вместо «888000»), а большинство — ни разу. Сумма номера — та, что прочли хотя бы дважды; иначе `sure` ложно, и
 * текст встаёт в поле на проверку, а не сразу в разбор. Итог — чистый текст «номер сумма» строками: его разбирает тот же
 * `ValuationText`, что и вставленное сообщение.
 */
final class ValuationPhoto
{
    /** Длинная сторона больше — масштаб не берётся: страница OCR дольше, а читаемость не растёт. */
    private const MAX_SIDE = 3200;

    private const SCALES = [1, 1.5, 2];

    /** @return array{text: string, sure: bool} */
    public static function read(string $path): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image) {
            return ['text' => '', 'sure' => false];
        }
        $dir = sys_get_temp_dir().'/xcar-valuation-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        try {
            $files = [];
            [$w, $h] = [imagesx($image), imagesy($image)];
            foreach (self::SCALES as $i => $k) {
                if ($i > 0 && max($w, $h) * $k > self::MAX_SIDE) {
                    continue;
                }
                $copy = imagecreatetruecolor((int) ($w * $k), (int) ($h * $k));
                imagecopyresampled($copy, $image, 0, 0, 0, 0, (int) ($w * $k), (int) ($h * $k), $w, $h);
                imagepng($copy, $files[] = "{$dir}/shot-{$i}.png");
            }
            $texts = self::ocr($files);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }

        return self::vote($texts);
    }

    /**
     * Прочтения разных масштабов → номер и сумма большинством.
     *
     * @param  list<string>  $texts
     * @return array{text: string, sure: bool}
     */
    public static function vote(array $texts): array
    {
        $refs = [];
        foreach ($texts as $text) {
            foreach (ValuationText::parse($text)['rows'] as $row) {
                $refs[$row['key']] ??= ['ref' => $row['ref'], 'amounts' => [], 'vin' => null, 'city' => null];
                $row['amount'] !== null && $refs[$row['key']]['amounts'][] = $row['amount'];
                $refs[$row['key']]['vin'] ??= $row['vin'];
                $refs[$row['key']]['city'] ??= $row['city']['name'] ?? null;
            }
        }
        if ($refs === []) {
            return ['text' => trim($texts[0] ?? ''), 'sure' => false];
        }
        $sure = true;
        $lines = [];
        foreach ($refs as $r) {
            $counts = array_count_values($r['amounts']);
            arsort($counts);
            // Ничья — у первого прочтения (обычный масштаб) приоритет.
            $best = $counts ? (max($counts) > 1 || count($r['amounts']) === 1 ? array_key_first($counts) : $r['amounts'][0]) : null;
            $sure = $sure && $best !== null && ($counts[$best] ?? 0) >= min(2, count($texts));
            $lines[] = trim($r['ref'].' '.$best);
            $r['vin'] && $lines[] = $r['vin'];
            $r['city'] && $lines[] = $r['city'];
        }

        return ['text' => implode("\n", $lines), 'sure' => $sure];
    }

    /** @return list<string> текст каждой картинки по порядку; не прочлась — пусто */
    private static function ocr(array $files): array
    {
        if (! $files) {
            return [];
        }
        // Запрос ждёт ответа: на проде три масштаба скрина — 12–15 с, предел у сервера 30.
        $result = Process::timeout(25)->run(['nice', '-n', '5', config('xcar.ocr', 'ocr'), ...$files]);
        $texts = array_fill_keys($files, '');
        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($texts[$row['file'] ?? ''])) {
                $texts[$row['file']] = (string) ($row['text'] ?? '');
            }
        }
        if (! $result->successful()) {
            Log::warning('Оценка с фото: ocr упал', ['error' => mb_substr($result->errorOutput(), -500)]);
        }

        return array_values($texts);
    }
}
