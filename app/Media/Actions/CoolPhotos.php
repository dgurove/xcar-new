<?php

namespace App\Media\Actions;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Холодный слой: у давно закрытых машин конверсии стираются, остаётся один
 * оригинал — страницы отдают его (MediaUrl подставляет оригинал, пока
 * конверсии нет), а WarmPhotos досчитает их, когда карточку снова откроют.
 */
final class CoolPhotos
{
    /** @return array{media: int, files: int, bytes: int} */
    public function __invoke(iterable $media, bool $dryRun = false): array
    {
        $stat = ['media' => 0, 'files' => 0, 'bytes' => 0];
        /** @var Media $m */
        foreach ($media as $m) {
            if (! array_filter((array) $m->generated_conversions)) {
                continue;
            }
            // Каталог конверсий целиком: в нём могут лежать и файлы конверсий, которых в коде уже нет.
            $dir = dirname($m->getPath()).'/conversions';
            foreach (is_dir($dir) ? (array) glob("{$dir}/*") : [] as $path) {
                $stat['files']++;
                $stat['bytes'] += (int) filesize($path);
                $dryRun || @unlink($path);
            }
            $stat['media']++;
            if (! $dryRun) {
                $m->forceFill(['generated_conversions' => []])->saveQuietly();
                is_dir($dir) && @rmdir($dir);
            }
        }

        return $stat;
    }
}
