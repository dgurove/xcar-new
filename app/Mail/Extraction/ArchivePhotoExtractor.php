<?php



namespace App\Mail\Extraction;

use App\Mail\Attachment;
use Archive7z\Archive7z;
use Throwable;
use ZipArchive;

/**
 * Содержимое архива письма: фотографии, список записей и предпросмотр каждой.
 *
 * Страховые иногда присылают снимки упакованными в архив, и модератору
 * пришлось бы скачивать, распаковывать и перезаливать каждый файл руками.
 * Здесь архив разворачивается в фотографии коллекции, а клиенту отдаётся
 * список записей, каждую из которых можно прочитать по её месту в списке.
 *
 * Архив недоверенный — он пришёл по почте. Zip читает сам PHP (`getFromIndex`),
 * 7z и rar — бинарь `7z` (gemorroj/archive7z): `extractTo()` раскладывает файлы
 * по путям из архива и умеет уйти на диск (path traversal), а мы читаем каждый
 * файл в память и сами решаем, как его назвать.
 */
final class ArchivePhotoExtractor
{
    /** MIME, которые читает сам PHP через ZipArchive. */
    private const ZIP_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
    ];

    /** MIME, которые читает бинарь 7z: 7z и rar. */
    private const SEVEN_ZIP_MIMES = [
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/x-rar',
    ];

    /** Суммарный несжатый размер архива. */
    private const MAX_TOTAL_BYTES = 1_500_000_000;

    /** Один файл. */
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;

    /**
     * Число записей в архиве.
     *
     * Тысяча, а не двести: у поставщика закупок в архиве бывает под четыреста
     * кадров, и двухсотый по счёту делал бы пустым весь архив. От бомбы это
     * не защищало — защищают лимиты по байтам.
     */
    private const MAX_ENTRIES = 1000;

    /**
     * Сколько слоёв архивов разворачивается.
     *
     * Три уже встретились в жизни: обёртка облака поставщика → архив с
     * фотографиями → снимки. Четвёртый — запас, дальше это уже не упаковка,
     * а способ занять работника навсегда.
     */
    private const MAX_DEPTH = 4;

    /** Отношение несжатого размера к сжатому: защита от zip-бомбы. */
    private const MAX_COMPRESSION_RATIO = 1000;

    /**
     * Все фотографии из архива.
     *
     * Любая ошибка — битый архив, слишком большой файл, странная запись —
     * возвращает пустой список: промоут кандидата не должен падать из-за
     * одного письма.
     *
     * @return list<array{name: string, mime: string, contents: string}>
     */
    public function extractPhotos(Attachment $attachment, int $limit = 0): array
    {
        return $this->withArchive((string)$attachment->contents(), (string)$attachment->mime, function (ZipArchive|Archive7z $archive) use ($limit): array {
            $photos = [];
            $taken = [];

            foreach ($this->safeEntries($archive) as $entry) {
                if (!$this->isImageName($entry['name'])) {
                    continue;
                }

                $contents = $this->readEntry($archive, $entry);

                if ($contents === null) {
                    continue;
                }

                $base = $this->uniqueName(basename($entry['name']), $taken);
                $photos[] = [
                    'name' => $base,
                    'mime' => $this->mimeOf($base),
                    'contents' => $contents,
                ];

                if ($limit > 0 && count($photos) >= $limit) {
                    break;
                }
            }

            return $photos;
        }) ?? [];
    }

    /**
     * Первый снимок из архива — для превью на плитке кандидата.
     *
     * Плитка показывает одну картинку, и ради неё не нужно распаковывать весь
     * архив: в solid-7z извлечение каждого файла идёт с начала блока.
     *
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function firstImage(Attachment $attachment): ?array
    {
        $photos = $this->extractPhotos($attachment, 1);

        return $photos[0] ?? null;
    }

    /**
     * Все безопасные записи архива с их метаданными.
     *
     * index — место записи в отфильтрованном списке (0-based), по нему же
     * позже читается содержимое (entryContents). Сырой zip-индекс наружу
     * не выходит: он свой у каждого формата.
     *
     * @return list<array{index: int, name: string, mime: string, size: int}>
     */
    public function listEntries(Attachment $attachment): array
    {
        return $this->listEntriesFor((string)$attachment->contents(), (string)$attachment->mime);
    }

    /**
     * Список записей архива по содержимому и MIME — для документов оффера,
     * у которых нет модели вложения письма.
     *
     * @return list<array{index: int, name: string, mime: string, size: int}>
     */
    public function listEntriesFor(string $contents, string $mime): array
    {
        return $this->withArchive($contents, $mime, function (ZipArchive|Archive7z $archive): array {
            $entries = [];

            foreach ($this->safeEntries($archive) as $index => $entry) {
                $entries[] = [
                    'index' => $index,
                    'name' => $entry['name'],
                    'mime' => $this->mimeOf($entry['name']),
                    'size' => $entry['size'],
                ];
            }

            return $entries;
        }) ?? [];
    }

    /**
     * Содержимое одной записи архива по её месту в списке безопасных записей.
     *
     * Для solid-7z это перечисление всего архива и чтение одного файла с
     * начала блока — для предпросмотра одной записи это приемлемо.
     *
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function entryContents(Attachment $attachment, int $index): ?array
    {
        return $this->entryContentsFor((string)$attachment->contents(), (string)$attachment->mime, $index);
    }

    /**
     * Содержимое одной записи архива по содержимому и MIME — для документов
     * оффера, у которых нет модели вложения письма.
     *
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function entryContentsFor(string $contents, string $mime, int $index): ?array
    {
        return $this->withArchive($contents, $mime, function (ZipArchive|Archive7z $archive) use ($index): ?array {
            $entries = $this->safeEntries($archive);
            $entry = $entries[$index] ?? null;

            if ($entry === null) {
                return null;
            }

            $contents = $this->readEntry($archive, $entry);

            if ($contents === null) {
                return null;
            }

            return [
                'name' => $entry['name'],
                'mime' => $this->mimeOf($entry['name']),
                'contents' => $contents,
            ];
        });
    }

    /**
     * Кадры из архива на диске — по одному, колбэком, с заходом во вложенные
     * архивы.
     *
     * Файлом, а не строкой: архив закупки бывает в сотни мегабайт, а
     * нехватка памяти в этом проекте уже роняла работника очереди вместе с
     * почтой. Кадр читается в память один и тут же отдаётся; вложенный архив
     * переливается на диск потоком и снимается сразу после обхода.
     *
     * Формат — по сигнатуре, а не по mime: у файла на диске mime нет.
     *
     * @param callable(string $name, string $contents): void $onPhoto
     * @return int сколько кадров отдано
     */
    public function walkFile(string $path, callable $onPhoto, int $limit = 0): int
    {
        $taken = [];
        $count = 0;

        $this->walk($path, $onPhoto, $limit, 0, $taken, $count);

        return $count;
    }

    /** Архив по имени файла: то, что мы умеем развернуть. */
    public static function isArchiveName(string $name): bool
    {
        return (bool)preg_match('/\.(zip|7z|rar)$/i', $name);
    }

    /**
     * @param callable(string, string): void $onPhoto
     * @param array<string, true> $taken
     */
    private function walk(string $path, callable $onPhoto, int $limit, int $depth, array &$taken, int &$count): void
    {
        $this->withFile($path, function (ZipArchive|Archive7z $archive) use ($onPhoto, $limit, $depth, &$taken, &$count): void {
            foreach ($this->safeEntries($archive) as $entry) {
                if ($limit > 0 && $count >= $limit) {
                    return;
                }

                if ($this->isImageName($entry['name'])) {
                    $contents = $this->readEntry($archive, $entry);

                    if ($contents === null) {
                        continue;
                    }

                    $count++;
                    $onPhoto($this->uniqueName(basename($entry['name']), $taken), $contents);

                    continue;
                }

                if (!self::isArchiveName($entry['name']) || $depth >= self::MAX_DEPTH) {
                    continue;
                }

                $nested = $this->spill($archive, $entry);

                if ($nested === null) {
                    continue;
                }

                try {
                    $this->walk($nested, $onPhoto, $limit, $depth + 1, $taken, $count);
                } finally {
                    @unlink($nested);
                }
            }
        });
    }

    /**
     * Открыть архив с диска и позвать колбэк. Zip — по сигнатуре `PK`,
     * остальное — бинарём 7z. Любая ошибка — null, как в withArchive().
     */
    private function withFile(string $path, callable $callback): mixed
    {
        try {
            $head = (string)@file_get_contents($path, false, null, 0, 2);

            return $head === 'PK'
                ? $this->withZip($path, $callback)
                : $this->withSevenZip($path, $callback);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Вложенный архив — на диск, во временный файл.
     *
     * Из zip — потоком, чтобы архив на сто мегабайт не проходил через память.
     * Из 7z бинарь отдаёт только содержимое целиком; вложенные архивы там
     * редкость, и это терпимо.
     *
     * Расширение сохраняется: по нему тот же обход решает, что это архив.
     *
     * @param array{name: string, size: int, packedSize: int, zipIndex: int|null, sevenZipPath: string|null} $entry
     */
    private function spill(ZipArchive|Archive7z $archive, array $entry): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'xcar-nest-');

        if ($base === false) {
            return null;
        }

        $path = $base . '.' . mb_strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
        @unlink($base);

        try {
            if ($archive instanceof ZipArchive) {
                /** @var int $zipIndex позиция в ZipArchive — задаёт rawZipEntries */
                $zipIndex = $entry['zipIndex'];
                $in = $archive->getStreamIndex($zipIndex);
                $out = $in === false ? false : fopen($path, 'wb');

                if ($in === false || $out === false) {
                    return null;
                }

                try {
                    stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                    fclose($out);
                }

                return $path;
            }

            $contents = $this->readEntry($archive, $entry);

            if ($contents === null || file_put_contents($path, $contents) === false) {
                return null;
            }

            return $path;
        } catch (Throwable) {
            @unlink($path);

            return null;
        }
    }

    /** Архив с фотографиями (zip/7z/rar), который мы умеем распаковывать. */
    public static function isArchive(?string $mime): bool
    {
        $mime = mb_strtolower((string)$mime);

        return in_array($mime, self::ZIP_MIMES, true)
            || in_array($mime, self::SEVEN_ZIP_MIMES, true);
    }

    /**
     * Открыть архив из байтов и MIME и позвать колбэк с открытым архивом.
     *
     * Любая ошибка — битый архив, пустые данные, странная запись — это null,
     * а не исключение: вызывающий решает, что вернуть.
     */
    private function withArchive(string $contents, string $mime, callable $callback): mixed
    {
        $mime = mb_strtolower($mime);
        $viaSevenZip = in_array($mime, self::SEVEN_ZIP_MIMES, true);

        if (!in_array($mime, self::ZIP_MIMES, true) && !$viaSevenZip) {
            return null;
        }

        try {
            $bytes = $contents;

            if ($bytes === null || $bytes === '') {
                return null;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'xcar-arch-');

            if ($tempFile === false) {
                return null;
            }

            if (file_put_contents($tempFile, $bytes) === false) {
                @unlink($tempFile);

                return null;
            }

            try {
                return $viaSevenZip
                    ? $this->withSevenZip($tempFile, $callback)
                    : $this->withZip($tempFile, $callback);
            } finally {
                @unlink($tempFile);
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function withZip(string $tempFile, callable $callback): mixed
    {
        $zip = new ZipArchive();

        if ($zip->open($tempFile) !== true) {
            return null;
        }

        try {
            return $callback($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * 7z и rar.
     *
     * Бинарь `7z` умеет читать оба формата, но писать rar — нет, поэтому
     * тесты крутятся на 7z, а rar идёт тем же путём.
     */
    private function withSevenZip(string $tempFile, callable $callback): mixed
    {
        $archive = new Archive7z($tempFile);

        // битый архив — не архив, но и не ошибка: промоут не должен падать
        if (!$archive->isValid()) {
            return null;
        }

        return $callback($archive);
    }

    /**
     * Безопасные записи архива после лимитов.
     *
     * Каталоги, симлинки и имена с path traversal уже отсеяны в rawEntries;
     * здесь остались только те записи, которые можно прочитать. Лимиты —
     * защита от zip-бомбы: нарушил любой — отдаём пусто, а не половину архива.
     *
     * `zipIndex` — позиция в ZipArchive (для getFromIndex), `sevenZipPath` —
     * исходное имя записи в архиве (для getContent); заполнено ровно одно из
     * двух, по формату архива.
     *
     * @return list<array{
     *     name: string,
     *     size: int,
     *     packedSize: int,
     *     zipIndex: int|null,
     *     sevenZipPath: string|null
     * }>
     */
    private function safeEntries(ZipArchive|Archive7z $archive): array
    {
        $entries = [];
        $totalSize = 0;

        foreach ($this->rawEntries($archive) as $raw) {
            [$name, $size, $packedSize, $zipIndex, $sevenZipPath] = $raw;

            // Вложенный архив в память целиком не читается — он уходит на
            // диск потоком, — и потолок одного файла к нему не относится.
            // Общий потолок и защита от бомбы действуют на него как на всех.
            if ($size > self::MAX_FILE_BYTES && !self::isArchiveName($name)) {
                return [];
            }

            if ($packedSize > 0 && $size / $packedSize > self::MAX_COMPRESSION_RATIO) {
                return [];
            }

            $entries[] = [
                'name' => $name,
                'size' => $size,
                'packedSize' => $packedSize,
                'zipIndex' => $zipIndex,
                'sevenZipPath' => $sevenZipPath,
            ];

            $totalSize += $size;

            if (count($entries) > self::MAX_ENTRIES || $totalSize > self::MAX_TOTAL_BYTES) {
                return [];
            }
        }

        return $entries;
    }

    /**
     * Сырые записи архива после фильтров безопасности, до лимитов.
     *
     * Форма у zip и 7z одна, чтобы ниже был один путь без развилок по формату.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int|null, 4: string|null}>
     */
    private function rawEntries(ZipArchive|Archive7z $archive): array
    {
        if ($archive instanceof ZipArchive) {
            return $this->rawZipEntries($archive);
        }

        return $this->rawSevenZipEntries($archive);
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int|null, 4: string|null}>
     */
    private function rawZipEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if ($name === false || $this->isSuspiciousEntry($name, $zip, $index)) {
                continue;
            }

            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            $entries[] = [
                $name,
                (int)($stat['size'] ?? 0),
                (int)($stat['comp_size'] ?? 0),
                $index,
                null,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int|null, 4: string|null}>
     */
    private function rawSevenZipEntries(Archive7z $archive): array
    {
        $entries = [];

        foreach ($archive->getEntries() as $entry) {
            // каталогу места в фотографиях нет, пароля от архива у нас нет
            if ($entry->isDirectory() || $entry->isEncrypted()) {
                continue;
            }

            // rar с Windows хранит пути с `\` — нормализуем до `/`, чтобы
            // isSafeName() резал именно traversal, а не легитимные пути
            $path = $entry->getUnixPath();

            if (!$this->isSafeName($path)) {
                continue;
            }

            $entries[] = [
                $path,
                (int)$entry->getSize(),
                (int)$entry->getPackedSize(),
                null,
                $entry->getPath(),
            ];
        }

        return $entries;
    }

    /**
     * Содержимое записи: zip читает сам PHP, 7z — бинарь.
     *
     * @param array{name: string, size: int, packedSize: int, zipIndex: int|null, sevenZipPath: string|null} $entry
     */
    private function readEntry(ZipArchive|Archive7z $archive, array $entry): ?string
    {
        if ($archive instanceof ZipArchive) {
            /** @var int $zipIndex позиция в ZipArchive — задаёт rawZipEntries */
            $zipIndex = $entry['zipIndex'];

            $contents = $archive->getFromIndex($zipIndex);

            return $contents === false ? null : $contents;
        }

        // содержимое читаем по исходному имени записи — так бинарь точнее
        // находит её, чем по нормализованному
        /** @var string $path исходное имя записи — задаёт rawSevenZipEntries */
        $path = $entry['sevenZipPath'];

        $contents = $archive->getContent($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Пропускает каталоги, symlink-записи и имена, уводящие с пути архива.
     */
    private function isSuspiciousEntry(string $name, ZipArchive $zip, int $index): bool
    {
        // каталог
        if (str_ends_with($name, '/')) {
            return true;
        }

        // симлинк: распаковывать по нему нельзя — он может смотреть наружу
        $opsys = 0;
        $attributes = 0;

        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && ($attributes & 0xF000) === 0xA000) {
            return true;
        }

        return !$this->isSafeName($name);
    }

    private function isSafeName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        // абсолютный путь и бэкслэши: имя пришло из чужого архива
        if (str_starts_with($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        $base = basename($name);

        return $base !== '' && $base !== '.' && $base !== '..';
    }

    /**
     * Внутри архива mime нет — определяем по расширению имени.
     */
    private function isImageName(string $name): bool
    {
        return (bool)preg_match('/\.(jpe?g|png|webp)$/i', $name);
    }

    private function mimeOf(string $name): string
    {
        return match (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * Два файла из разных каталогов архива могут называться одинаково —
     * в одну коллекцию оба не влезут.
     *
     * @param array<string, true> $taken
     */
    private function uniqueName(string $name, array &$taken): string
    {
        if (!isset($taken[$name])) {
            $taken[$name] = true;

            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);

        for ($n = 2; isset($taken["{$stem}-{$n}.{$ext}"]); $n++) {
        }

        $unique = "{$stem}-{$n}.{$ext}";
        $taken[$unique] = true;

        return $unique;
    }
}
