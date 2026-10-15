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

// Цвет строки состояния до первой отрисовки ($bar в x-ui.layout) — тот же, что у шапки (--color-bar в app.css):
// разойдутся — строка под часами и шапка станут разного цвета, и нигде не будет ошибки.
$css = file_get_contents(__DIR__.'/../resources/css/app.css');
$layout = file_get_contents(__DIR__.'/../resources/views/components/ui/layout.blade.php');
preg_match('/@theme\s*\{[^}]*?--color-bar:\s*(#[0-9a-f]+)/i', $css, $light);
preg_match('/\n\.dark\s*\{[^}]*?--color-bar:\s*(#[0-9a-f]+)/i', $css, $dark);
preg_match("/\\\$bar = \\['light' => '(#[0-9a-f]+)', 'dark' => '(#[0-9a-f]+)'\\]/i", $layout, $bar);
if (! $light || ! $dark || ! $bar || strcasecmp($light[1], $bar[1]) || strcasecmp($dark[1], $bar[2])) {
    $bad[] = 'components/ui/layout.blade.php: $bar не совпадает с --color-bar в app.css';
}

if ($bad) {
    fwrite(STDERR, "Шаблоны не собираются:\n  ".implode("\n  ", $bad)."\n");
    exit(1);
}
echo "Шаблоны собираются\n";
