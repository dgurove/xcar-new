<?php

/**
 * Сторож шаблонов перед выкладкой (11.10.2026): компилирует каждый resources/views/**.blade.php и проверяет `php -l`.
 * `view:cache` при сборке образа синтаксис компилята не проверяет — «</x-slot:badge>@endif» вплотную собирался в
 * нерабочий PHP, и карточка сделки CRM падала у всех сутки. Выход 1 и список файлов — если хоть один не собрался.
 *
 *   php scripts/blade-lint.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$compiler = app('blade.compiler');
$dir = sys_get_temp_dir().'/blade-lint-'.getmypid();
@mkdir($dir);
$bad = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $out = $dir.'/'.md5($file->getPathname()).'.php';
    file_put_contents($out, $compiler->compileString(file_get_contents($file->getPathname())));
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($out).' 2>&1', $lines, $code);
    if ($code !== 0) {
        $bad[] = str_replace(realpath(__DIR__.'/..').'/', '', realpath($file->getPathname())).': '.trim(preg_replace('/ in \S+ on line/', ', строка', implode(' ', array_filter($lines, fn ($l) => str_contains($l, 'error'))))); 
    }
    $lines = [];
    unlink($out);
}
@rmdir($dir);

if ($bad) {
    fwrite(STDERR, "Шаблоны не собираются:\n  ".implode("\n  ", $bad)."\n");
    exit(1);
}
echo "Шаблоны собираются\n";
