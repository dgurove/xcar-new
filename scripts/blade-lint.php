<?php

/**
 * Сторож шаблонов перед выкладкой (11.10.2026): компилирует каждый resources/views/**.blade.php и проверяет `php -l`.
 * `view:cache` при сборке образа синтаксис компилята не проверяет — «</x-slot:badge>@endif» вплотную собирался в
 * нерабочий PHP, и карточка сделки CRM падала у всех сутки. Заодно — цвет полосы и копии мимо общих дверей (ниже).
 * Выход 1 и список мест — если хоть что-то не так.
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
preg_match("/'theme_color' => '(#[0-9a-f]+)'/i", file_get_contents(__DIR__.'/../app/Http/Pwa/PwaController.php'), $manifest);
if (! $manifest || ! $light || strcasecmp($manifest[1], $light[1])) {
    $bad[] = 'app/Http/Pwa/PwaController.php: theme_color манифеста не совпадает с --color-bar в app.css';
}

// Одна дверь на дело (16.10.2026: сумма разбиралась в шестнадцати местах, историю писали пять модулей, клавиатуру
// слушали четыре) — новая копия мимо двери ловится здесь, а не через месяц у человека на телефоне.
$root = realpath(__DIR__.'/..');
$doors = [
    ['app/Http', '/\.php$/', "/preg_replace\('\/\\\\D\+\/'/", [], 'сумма из формы — App\\Support\\Money::rubles'],
    ['resources/js', '/\.js$/', '/history\.pushState/', ['resources/js/overlays.js'], 'запись истории — resources/js/overlays.js'],
    ['resources/js', '/\.js$/', '/visualViewport\??\.addEventListener/', ['resources/js/keyboard.js'], 'клавиатура — keyboard.js и событие keyboard:change'],
    ['resources/views', '/\.blade\.php$/', '/<x-ui\.sheet.* :open="\$errors->(has|hasAny|any)\((\[[^\]]*\]|\'[^\']*\')?\)"/', [], 'шторка с ошибками открывается сама — _sheet в sheet.js'],
];
foreach ($doors as [$where, $type, $pattern, $allowed, $door]) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$where", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = str_replace("$root/", '', $file->getPathname());
        if (! preg_match($type, $rel) || in_array($rel, $allowed, true)) continue;
        foreach (file($file->getPathname()) as $n => $line) {
            if (preg_match($pattern, $line)) $bad[] = "$rel:".($n + 1).": мимо двери ($door)";
        }
    }
}

if ($bad) {
    fwrite(STDERR, "Сторож выкладки не пропускает:\n  ".implode("\n  ", $bad)."\n");
    exit(1);
}
echo "Шаблоны собираются\n";
