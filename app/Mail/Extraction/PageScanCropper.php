<?php



namespace App\Mail\Extraction;

use GdImage;

/**
 * Фотография с распечатанной страницы.
 *
 * Часть отправителей не прикладывает снимки как есть: сначала их вставляют на
 * лист, под каждым печатают имя исходного файла, лист сохраняют картинкой и уже
 * её кладут в архив. Оригинала внутри нет — пришёл растр, и в каталог такой лист
 * идти не должен: там белые поля и подпись вместо машины.
 *
 * Здесь лист разбирается обратно. Белые поля снимает GD, дальше страница
 * читается профилем строк: полосы содержимого, разделённые белыми промежутками.
 * Высокая полоса — фотография, тонкая — подпись, колонтитул или логотип. Текст
 * разбирать не нужно, достаточно высоты.
 *
 * Ничего не выбрасываем молча: любая проверка не сошлась — возвращается пустой
 * список, и вызывающий кладёт файл как был. Лишний белый лист в черновике
 * модератор увидит и удалит, потерянный снимок — нет.
 */
final class PageScanCropper
{
    /** Яркость, ниже которой пиксель считается содержимым, а не полем листа. */
    private const INK_LEVEL = 240;

    /** Ширина бокового поля, по которому лист отличается от фотографии. */
    private const MARGIN_BAND = 0.02;

    /** Доля бумаги в боковом поле, ниже которой это не лист. */
    private const MARGIN_WHITE = 0.9;

    /** Ширина уменьшенной копии, по которой считаются профили. */
    private const PROBE_WIDTH = 400;

    /** Доля строки, закрашенная содержимым: ниже — промежуток между полосами. */
    private const ROW_INK_SHARE = 0.02;

    /** Высота полосы в долях листа: ниже — подпись или колонтитул, не фото. */
    private const MIN_BAND_SHARE = 0.15;

    /** Доля площади листа, ниже которой вырезанное на фотографию не тянет. */
    private const MIN_AREA_SHARE = 0.10;

    /** Сторона вырезанного снимка в пикселях. */
    private const MIN_SIDE = 400;

    /** Границы соотношения сторон: за ними это полоска, а не снимок. */
    private const MIN_RATIO = 0.3;
    private const MAX_RATIO = 3.5;

    /**
     * Доля содержимого внутри вырезанного, ниже которой это не снимок.
     *
     * Отделяет фотографию от страницы текста: снимок закрашен целиком, у листа
     * с текстом между строк и справа от них остаётся бумага. На письмах проекта
     * снимки дают 0.87–0.99, скан акта осмотра — 0.63.
     */
    private const MIN_INK_SHARE = 0.78;

    /** Отступ внутрь от найденной границы: рамка и ореол JPEG. */
    private const INSET = 2;

    /** Качество JPEG на выходе: лист уже пережат, второй раз давить незачем. */
    private const JPEG_QUALITY = 92;

    /**
     * Фотографии с листа.
     *
     * Пустой список означает «это не лист» либо «разобрать не вышло» — в обоих
     * случаях исходный файл остаётся нетронутым.
     *
     * @return list<string> байты JPEG, по одному на найденный снимок
     */
    public function crop(string $contents): array
    {
        $page = @imagecreatefromstring($contents);

        if ($page === false) {
            return [];
        }

        $page = $this->upright($page, $contents);

        if (!$this->looksLikePage($page)) {
            return [];
        }

        return $this->cutBands($page);
    }

    // ------------------------------------------------------------- поворот

    /**
     * Лист правильной стороной.
     *
     * Сначала EXIF: если отправитель сохранил ориентацию, спорить не о чем.
     * Иначе смотрим на сам лист — он вертикальный, и если картинка лежит на
     * боку, её надо развернуть. Куда именно, показывает подпись: у страницы она
     * внизу, а тонкая плотная полоса у края видна в профиле. Та самая подпись,
     * которую мы потом выбросим, здесь работает указателем.
     *
     * Крутим лист, а не вырезанный снимок: после обрезки от подписи не остаётся
     * ничего, и определять будет уже не по чему.
     */
    private function upright(GdImage $page, string $contents): GdImage
    {
        $byExif = $this->exifRotation($contents);

        if ($byExif !== 0) {
            return $this->rotate($page, $byExif);
        }

        // вертикальный лист уже стоит правильно
        if (imagesy($page) >= imagesx($page)) {
            return $page;
        }

        /*
         * Крутим лист так, чтобы подпись вернулась вниз. GD с положительным
         * углом поворачивает против часовой: левый край уходит вниз, правый —
         * наверх. Значит подпись слева — это +90, подпись справа — -90.
         */
        return match ($this->captionEdge($page)) {
            'left' => $this->rotate($page, 90),
            'right' => $this->rotate($page, -90),
            default => $page,
        };
    }

    /**
     * У какого края лежащего листа тонкая полоса содержимого.
     *
     * Считаем профиль по колонкам и берём крайние широкие полосы — это
     * фотографии. Подпись в них не попадает: она узкая. Остаётся посмотреть,
     * за какой из них есть содержимое: с той стороны и была нижняя кромка
     * листа. Пусто с обеих — возвращаем null, лист останется как есть.
     */
    private function captionEdge(GdImage $page): ?string
    {
        $probe = $this->probe($page);
        $profile = $this->inkProfile($probe, vertical: false);
        $bands = $this->bands($profile, (int)ceil(count($profile) * self::MIN_BAND_SHARE));

        if ($bands === []) {
            return null;
        }

        $width = count($profile);
        $left = $this->ink($profile, 0, $bands[0]['from'] - 1);
        $right = $this->ink($profile, $bands[count($bands) - 1]['to'] + 1, $width - 1);

        if ($left === $right) {
            return null;
        }

        return $left > $right ? 'left' : 'right';
    }

    /** Угол из EXIF: только повороты, отражения на сканах листов не бывает. */
    private function exifRotation(string $contents): int
    {
        if (!function_exists('exif_read_data')) {
            return 0;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));

        return match ((int)($exif['Orientation'] ?? 0)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }


        return $rotated;
    }

    // -------------------------------------------------------------- разбор

    /**
     * Лист ли это.
     *
     * Смотрим боковые поля, а не общую долю белого. Сначала стоял именно порог
     * по белому — и на боевых письмах он пропустил двадцать листов из полусотни:
     * снимок там сверстан почти во всю страницу, белого остаётся четверть, и лист
     * выглядел как обычная фотография.
     *
     * Боковые поля белые у любого листа, даже свёрстанного в обрез: вверху и
     * внизу снимок к кромке прижаться может, слева и справа — нет. У фотографии
     * с камеры двух белых полей по бокам не бывает.
     */
    private function looksLikePage(GdImage $page): bool
    {
        $probe = $this->probe($page);
        $width = imagesx($probe);
        $margin = max(2, (int)($width * self::MARGIN_BAND));

        return $this->whiteShare($probe, 0, $margin) >= self::MARGIN_WHITE
            && $this->whiteShare($probe, $width - 1 - $margin, $width - 1) >= self::MARGIN_WHITE;
    }

    /** Доля бумаги в вертикальной полосе от колонки до колонки. */
    private function whiteShare(GdImage $probe, int $from, int $to): float
    {
        $height = imagesy($probe);
        $width = imagesx($probe);
        $white = 0;
        $total = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = max(0, $from); $x <= min($to, $width - 1); $x++) {
                $total++;

                if ($this->luminance($probe, $x, $y) >= self::INK_LEVEL) {
                    $white++;
                }
            }
        }

        return $total === 0 ? 0.0 : $white / $total;
    }

    /** Доля пикселей, отличных от бумаги. */
    private function inkShare(GdImage $image): float
    {
        $probe = $this->probe($image);
        $width = imagesx($probe);
        $height = imagesy($probe);
        $ink = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($this->luminance($probe, $x, $y) < self::INK_LEVEL) {
                    $ink++;
                }
            }
        }

        return $ink / max(1, $width * $height);
    }

    /**
     * Снимки с листа.
     *
     * Поля срезает `imagecropauto` — это C, а не цикл по пикселям в PHP.
     * Дальше профиль строк даёт полосы; те, что ниже порога, — подпись и
     * колонтитулы, они отбрасываются. Каждая оставшаяся полоса обрезается ещё и
     * по горизонтали: на листе снимок уже полей.
     *
     * @return list<string>
     */
    private function cutBands(GdImage $page): array
    {
        $trimmed = @imagecropauto($page, IMG_CROP_THRESHOLD, 0.2, 0xFFFFFF);

        if ($trimmed === false) {
            $trimmed = $page;
        }

        $probe = $this->probe($trimmed);
        $scale = imagesx($trimmed) / max(1, imagesx($probe));

        $rows = $this->inkProfile($probe, vertical: true);
        $bands = $this->bands($rows, (int)ceil(count($rows) * self::MIN_BAND_SHARE));

        $photos = [];

        foreach ($bands as $band) {
            $photo = $this->cutBand($trimmed, $probe, $band, $scale);

            if ($photo !== null) {
                $photos[] = $photo;
            }
        }


        return $photos;
    }

    /**
     * Одна полоса листа в отдельный JPEG.
     *
     * Границы найдены на уменьшенной копии, поэтому возвращаются в исходный
     * масштаб и поджимаются внутрь: на краю остаётся рамка и ореол JPEG.
     *
     * @param array{from: int, to: int} $band
     */
    private function cutBand(GdImage $source, GdImage $probe, array $band, float $scale): ?string
    {
        $columns = $this->inkProfile($probe, vertical: false, from: $band['from'], to: $band['to']);
        $span = $this->span($columns);

        if ($span === null) {
            return null;
        }

        $rect = [
            'x' => (int)round($span['from'] * $scale) + self::INSET,
            'y' => (int)round($band['from'] * $scale) + self::INSET,
            'width' => (int)round(($span['to'] - $span['from'] + 1) * $scale) - self::INSET * 2,
            'height' => (int)round(($band['to'] - $band['from'] + 1) * $scale) - self::INSET * 2,
        ];

        if (!$this->plausible($rect, $source)) {
            return null;
        }

        $cut = @imagecrop($source, $rect);

        if ($cut === false) {
            return null;
        }

        if ($this->inkShare($cut) < self::MIN_INK_SHARE) {

            return null;
        }

        ob_start();
        imagejpeg($cut, null, self::JPEG_QUALITY);
        $bytes = (string)ob_get_clean();

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Похоже ли вырезанное на фотографию.
     *
     * Три независимые проверки: размер в пикселях, доля листа и пропорции.
     * Не сошлась любая — снимок не отдаём, лист уйдёт целиком.
     *
     * @param array{x: int, y: int, width: int, height: int} $rect
     */
    private function plausible(array $rect, GdImage $source): bool
    {
        if ($rect['width'] < self::MIN_SIDE || $rect['height'] < self::MIN_SIDE) {
            return false;
        }

        $area = $rect['width'] * $rect['height'];
        $page = imagesx($source) * imagesy($source);
        if ($area / max(1, $page) < self::MIN_AREA_SHARE) {
            return false;
        }

        $ratio = $rect['height'] / $rect['width'];

        return $ratio >= self::MIN_RATIO && $ratio <= self::MAX_RATIO;
    }

    // ------------------------------------------------------------ профили

    /** Уменьшенная копия: по ней считаются все профили. */
    private function probe(GdImage $image): GdImage
    {
        $width = min(self::PROBE_WIDTH, imagesx($image));
        $probe = imagescale($image, $width);

        return $probe === false ? $image : $probe;
    }

    /**
     * Сколько пикселей содержимого в каждой строке (или колонке).
     *
     * @return list<int>
     */
    private function inkProfile(GdImage $probe, bool $vertical, int $from = 0, ?int $to = null): array
    {
        $width = imagesx($probe);
        $height = imagesy($probe);

        $outer = $vertical ? $height : $width;
        $innerFrom = $vertical ? 0 : $from;
        $innerTo = $vertical ? $width - 1 : ($to ?? $height - 1);

        $profile = [];

        for ($i = 0; $i < $outer; $i++) {
            $ink = 0;

            for ($j = $innerFrom; $j <= $innerTo; $j++) {
                [$x, $y] = $vertical ? [$j, $i] : [$i, $j];

                if ($x >= $width || $y >= $height) {
                    continue;
                }

                if ($this->luminance($probe, $x, $y) < self::INK_LEVEL) {
                    $ink++;
                }
            }

            $profile[] = $ink;
        }

        return $profile;
    }

    /**
     * Полосы содержимого длиннее порога.
     *
     * Строка считается пустой, пока содержимого в ней меньше доли: у JPEG на
     * белом поле всегда есть шум, и ровного нуля не бывает.
     *
     * @param list<int> $profile
     * @return list<array{from: int, to: int}>
     */
    private function bands(array $profile, int $minLength): array
    {
        $length = count($profile);
        $threshold = max(1, (int)ceil($length * self::ROW_INK_SHARE));

        $bands = [];
        $start = null;

        for ($i = 0; $i < $length; $i++) {
            $filled = $profile[$i] >= $threshold;

            if ($filled && $start === null) {
                $start = $i;
            }

            if (!$filled && $start !== null) {
                $bands[] = ['from' => $start, 'to' => $i - 1];
                $start = null;
            }
        }

        if ($start !== null) {
            $bands[] = ['from' => $start, 'to' => $length - 1];
        }

        return array_values(array_filter(
            $bands,
            static fn (array $band): bool => $minLength <= $band['to'] - $band['from'] + 1,
        ));
    }

    /**
     * Крайние непустые позиции профиля.
     *
     * @param list<int> $profile
     * @return array{from: int, to: int}|null
     */
    private function span(array $profile): ?array
    {
        $threshold = max(1, (int)ceil(count($profile) * self::ROW_INK_SHARE));

        $from = null;
        $to = null;

        foreach ($profile as $i => $ink) {
            if ($ink < $threshold) {
                continue;
            }

            $from ??= $i;
            $to = $i;
        }

        return $from === null ? null : ['from' => $from, 'to' => (int)$to];
    }

    /**
     * Сколько содержимого на отрезке профиля.
     *
     * @param list<int> $profile
     */
    private function ink(array $profile, int $from, int $to): int
    {
        $sum = 0;

        for ($i = max(0, $from); $i <= min($to, count($profile) - 1); $i++) {
            $sum += $profile[$i];
        }

        return $sum;
    }

    private function luminance(GdImage $image, int $x, int $y): int
    {
        $color = imagecolorat($image, $x, $y);

        return (int)((
            (($color >> 16) & 0xFF) * 299
            + (($color >> 8) & 0xFF) * 587
            + ($color & 0xFF) * 114
        ) / 1000);
    }
}
