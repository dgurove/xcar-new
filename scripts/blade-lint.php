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
    // Дата — «07.10 07:40» одним правилом (30.10.2026): в разметке Ago::html / x-ui.when, текстом Ago::at (документы — свой вид, с годом).
    ['resources/views', '/\.blade\.php$/', "/translatedFormat\\('j [MF](,? H:i)?'\\)/", [], 'дата — App\\Support\\Ago::html или x-ui.when'],
    ['app', '/\.php$/', "/translatedFormat\\('j [MF](,? H:i)?'\\)/", [], 'дата — App\\Support\\Ago::at'],
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

// Два метода с одним именем в классе контроллера (17.10.2026: `run(files)` загрузки и `run(id, act)` действия над кадром в
// photos_controller — второй молча перекрыл первый, загрузка фото и файлов не работала полтора дня). Сборка такое пропускает.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/resources/js", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (! str_ends_with($file->getFilename(), '.js')) continue;
    // Внутри каждого класса отдельно: два класса в одном модуле вправе иметь по `connect()`. Кусок класса — от строки
    // `class …` до следующего объявления верхнего уровня; get/set с одним именем — пара, а не дубль.
    $parts = preg_split('/^(?=(?:export (?:default )?)?(?:class|function|const|let|var)\b)/m', file_get_contents($file->getPathname()));
    foreach ($parts as $part) {
        if (! preg_match('/^(?:export (?:default )?)?class\b/', $part)) continue;
        preg_match_all('/^    (?:async |static )?(get |set )?([A-Za-z_$][\w$]*)\([^)\n]*\)\s*\{/m', $part, $m, PREG_SET_ORDER);
        $names = array_map(fn ($x) => trim($x[1]).$x[2], $m);
        foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $name) {
            $bad[] = str_replace("$root/", '', $file->getPathname()).": метод {$name}() объявлен дважды — второй перекрывает первый";
        }
    }
}

// Компонент, который рисуют как шаблон (`view('components.…')`, `@include`), — в `AppServiceProvider::PLAIN_COMPONENTS`
// (29.10.2026): под Blaze файл компонента — определение функции, и такой вызов молча рисует пустоту (так пропал фрейм
// карточки строки `x-ui.detail`). В PHP имя вида компонента бывает только ради такого вызова — проверяется любое; имя,
// собранное из переменной или склейкой, не проверить — оно запрещено.
$plain = App\Providers\AppServiceProvider::PLAIN_COMPONENTS;
foreach ([['app', '/\.php$/', true], ['routes', '/\.php$/', true], ['resources/views', '/\.blade\.php$/', false]] as [$where, $type, $php]) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$where", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = str_replace("$root/", '', $file->getPathname());
        if (! preg_match($type, $rel)) continue;
        foreach (file($file->getPathname()) as $n => $line) {
            $named = $php ? '/([\'"])components\.([a-z0-9.\-]+)\1/'
                : '/(?:\bview|View::make|->make|@include(?:If|When|Unless|First)?|@each)\(\s*([\'"])components\.([a-z0-9.\-]+)\1/';
            $dynamic = $php ? '/"components\.[^"]*[$\{]|[\'"]components\.[\'"]\s*\./'
                : '/(?:\bview|View::make|->make|@include(?:If|When|Unless|First)?|@each)\(\s*(?:"components\.[^"]*[$\{]|[\'"]components\.[\'"]\s*\.)/';
            preg_match_all($named, $line, $m);
            foreach ($m[2] as $name) {
                if (! in_array(str_replace('.', '/', $name), $plain, true)) {
                    $bad[] = "$rel:".($n + 1).": components.$name рисуют как шаблон — впишите в AppServiceProvider::PLAIN_COMPONENTS";
                }
            }
            if (preg_match($dynamic, $line)) {
                $bad[] = "$rel:".($n + 1).': имя вида компонента собирается на ходу — под Blaze не проверить, пишите его целиком';
            }
        }
    }
}

// Модель без быстрого чтения полей (`Support\FastAttributes`, у дорожек машины — через `Cars\OnCar`), 29.10.2026: Eloquent
// на каждое чтение поля склеивает список кастов и ищет мутаторы — строки таблиц стоили до 8 мс.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (! str_ends_with($file->getFilename(), '.php')) continue;
    $code = file_get_contents($file->getPathname());
    if (preg_match('/^(?:final |abstract )?class \w+ extends (?:Model|Authenticatable|Pivot|MorphPivot)\b/m', $code)
        && ! preg_match('/^\s+use [^;]*\b(?:FastAttributes|OnCar)\b/m', $code)) {
        $bad[] = str_replace("$root/", '', $file->getPathname()).': модель без use FastAttributes;';
    }
}

// Селектор, из-за которого браузер пересчитывает всю страницу (29.10.2026, «Все» CRM: тост — 260 мс): `:has()` у корня
// (`html`, `body`, `:root`) и `:has()`, после которого справа `*`, псевдокласс без класса или голый тег — любая вставка узла
// пересчитывает все такие элементы. Состояние корня — классом (CLAUDE.md, «Стили без пересчёта всей страницы»).
foreach (glob("$root/resources/css/*.css") as $file) {
    $rel = str_replace("$root/", '', $file);
    $plain = preg_replace('#/\*.*?\*/#s', '', file_get_contents($file));
    preg_match_all('/([^{};]+)\{/', $plain, $rules);
    foreach ($rules[1] as $list) {
        if (! str_contains($list, ':has(')) continue;
        // Список селекторов — по запятым верхнего уровня.
        $parts = []; $depth = 0; $cur = '';
        foreach (str_split($list) as $ch) {
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            if ($ch === ',' && $depth === 0) { $parts[] = $cur; $cur = ''; } else { $cur .= $ch; }
        }
        $parts[] = $cur;
        foreach (array_map('trim', $parts) as $sel) {
            if (! str_contains($sel, ':has(')) continue;
            // `html:has(> body.app-shell)` — прямой ребёнок, вставки внутри страницы его не трогают.
            if (preg_match('/(^|[\s>+~&])(html|body|:root)([.#\[:][^\s>+~]*)?:has\((?!>\s*[\w.-]+\))/', $sel)) {
                $bad[] = "$rel: `:has()` у корня — состояние классом: $sel";
                continue;
            }
            // Правая часть после последнего `:has(…)`: есть в ней комбинатор вне скобок — последний составной селектор
            // должен держаться за класс, id или атрибут.
            $at = strrpos($sel, ':has(');
            for ($i = $at + 4, $depth = 0; $i < strlen($sel); $i++) {
                $depth += $sel[$i] === '(' ? 1 : ($sel[$i] === ')' ? -1 : 0);
                if ($depth === 0) break;
            }
            $tail = preg_replace('/\([^()]*\)/', '()', preg_replace('/\([^()]*\)/', '()', substr($sel, $i + 1)));
            $chunks = preg_split('/\s*[\s>+~]\s*/', rtrim(ltrim($tail, ')')));
            if (count($chunks) < 2) continue;
            $last = end($chunks);
            if (! preg_match('/[.#\[]/', $last)) {
                $bad[] = "$rel: после `:has()` справа «{$last}» — нужен класс: $sel";
            }
        }
    }
}

if ($bad) {
    fwrite(STDERR, "Сторож выкладки не пропускает:\n  ".implode("\n  ", $bad)."\n");
    exit(1);
}
echo "Шаблоны собираются\n";
