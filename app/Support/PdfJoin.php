<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Несколько PDF — одним по порядку (`pdfunite` из poppler-utils образа). Одна дверь «Приложить» (`Media\PhotosPdf::document`)
 * и пачки документов сделки (`Offers\Documents\ScanBundle`).
 */
final class PdfJoin
{
    public static function available(): bool
    {
        return (new ExecutableFinder)->find('pdfunite') !== null;
    }

    /** @param  list<string>  $paths  пути к PDF; один — его же содержимое */
    public static function files(array $paths): string
    {
        if (count($paths) === 1) {
            return (string) file_get_contents($paths[0]);
        }
        $unite = (new ExecutableFinder)->find('pdfunite') ?? throw new RuntimeException('PDF не склеить: нет pdfunite');
        $out = tempnam(sys_get_temp_dir(), 'join-').'.pdf';
        try {
            $run = Process::timeout(60)->run([$unite, ...$paths, $out]);
            if (! $run->successful() || ! is_file($out)) {
                throw new RuntimeException('PDF не склеился, приложите файлы по одному');
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -4));
        }
    }

    /** Сколько страниц: `pdfinfo`, без него — по словарю страниц. */
    public static function pages(string $path): int
    {
        if ($info = (new ExecutableFinder)->find('pdfinfo')) {
            $run = Process::timeout(20)->run([$info, $path]);
            if ($run->successful() && preg_match('/^Pages:\s+(\d+)/m', $run->output(), $m)) {
                return (int) $m[1];
            }
        }

        return max(1, preg_match_all('#/Type\s*/Page(?!s)#', (string) file_get_contents($path)));
    }
}
