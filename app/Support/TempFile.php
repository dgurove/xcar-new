<?php

namespace App\Support;

/**
 * Путь временного файла с расширением. `tempnam($dir, $p).'.pdf'` оставлял пустой файл-заготовку `tempnam` на каждый
 * вызов (05.11.2026): им пользовались счета, акты и выгрузки — в /tmp контейнера копились тысячи пустых файлов.
 */
final class TempFile
{
    public static function path(string $prefix, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        @unlink($base);

        return $base.'.'.ltrim($extension, '.');
    }
}
