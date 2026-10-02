<?php

namespace App\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Чужой водяной знак площадки (Мигторг) с кадра — скрипт `unmark` (deploy/bin/unmark), реестр знаков —
 * resources/watermarks. Знак снимается обратным смешиванием, без дорисовки: повреждения под ним остаются.
 * Знака нет или скрипт упал — null, кадр идёт как есть: приём фото из-за этого не ломается никогда.
 */
final class Unmark
{
    /**
     * $mark — знак выбрал человек (шторка «Водяной знак»): только он, место и масштаб ищутся шире, порог ниже.
     *
     * @return array{path: string, mark: string}|null чистый кадр во временном JPEG (удалить — забота вызвавшего)
     */
    public function __invoke(string $path, ?string $mark = null): ?array
    {
        $base = tempnam(sys_get_temp_dir(), 'unmark-');
        @unlink($base);
        $out = $base.'.jpg';
        try {
            // nice: сайт и почта на двух ядрах впереди; на кадр ~0,1 с, на 48 МП — секунды.
            $only = $mark === null ? [] : ['--mark', $mark];
            $result = Process::timeout(60)->run(['nice', '-n', '10', config('xcar.unmark', 'unmark'), ...$only, resource_path('watermarks'), $path, $out]);
            $line = trim((string) collect(explode("\n", trim($result->output())))->last());
            $answer = json_decode($line, true);
            if (! $result->successful() || ! is_array($answer)) {
                Log::warning('unmark упал', ['file' => basename($path), 'exit' => $result->exitCode(), 'stderr' => mb_substr($result->errorOutput(), -1500), 'out' => mb_substr($line, 0, 300)]);

                return $this->none($out);
            }
            if (empty($answer['mark']) || ! is_file($out) || filesize($out) === 0) {
                return $this->none($out);
            }

            return ['path' => $out, 'mark' => (string) $answer['mark']];
        } catch (Throwable $e) {
            Log::warning('unmark упал', ['file' => basename($path), 'error' => $e->getMessage()]);

            return $this->none($out);
        }
    }

    private function none(string $out): null
    {
        @unlink($out);

        return null;
    }
}
