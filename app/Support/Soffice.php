<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * LibreOffice без интерфейса — одна дверь конвертации документов (19.11.2026, владелец: договор «и в docx, чтобы что-то
 * корректировать»): HTML договора → Word (`DkpWord`), исправленный Word → PDF (`Offers\ContractEdit`). Свой профиль на каждый
 * вызов во временной папке: общий профиль два одновременных процесса делят плохо. Программа — `xcar.soffice` (в образе —
 * `soffice` из `libreoffice-writer-nogui`).
 */
final class Soffice
{
    /**
     * @param  string  $bytes  исходный файл
     * @param  string  $ext  его расширение (`html`, `docx`, `pdf`…)
     * @param  string  $to  во что: `docx` — Word 2007+, `pdf`
     */
    public static function convert(string $bytes, string $ext, string $to): string
    {
        $dir = sys_get_temp_dir().'/soffice-'.Str::random(12);
        File::ensureDirectoryExists($dir.'/out');
        try {
            $in = $dir.'/in.'.$ext;
            file_put_contents($in, $bytes);
            $filter = match ($to) {
                'docx' => 'docx:MS Word 2007 XML',
                'pdf' => 'pdf:writer_pdf_Export',
                default => throw new RuntimeException('Не умеем в '.$to),
            };
            $args = [config('xcar.soffice', 'soffice'), '-env:UserInstallation=file://'.$dir.'/profile', '--headless', '--norestore'];
            // HTML — в обычный Writer, а не в «веб-документ»: иначе в Word уходят поля и шрифты веб-страницы.
            if ($ext === 'html') {
                $args[] = '--infilter=HTML (StarWriter)';
            }
            $process = new Process([...$args, '--convert-to', $filter, '--outdir', $dir.'/out', $in]);
            $process->setTimeout(90)->run();
            $out = $dir.'/out/in.'.$to;
            if (! is_file($out)) {
                throw new RuntimeException('LibreOffice не собрал '.$to.': '.trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            return (string) file_get_contents($out);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
