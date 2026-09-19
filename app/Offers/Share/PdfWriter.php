<?php

namespace App\Offers\Share;

use RuntimeException;

/**
 * Собирает PDF из готовых JPEG: страница на фотографию, страница ровно по
 * размеру фотографии, ничего кроме неё.
 *
 * Почему не dompdf, которым это делалось раньше. Во-первых, размер страницы
 * в нём один на весь документ (@page), а нужна страница под каждое фото —
 * иначе кадры разных пропорций обрастают белыми полями. Во-вторых, dompdf
 * перекладывает картинку в свой поток, а здесь байты JPEG попадают в документ
 * как есть (фильтр DCTDecode): ни перекодирования, ни потери качества, и
 * размер файла равен сумме фотографий плюс пара килобайт разметки.
 *
 * Прогрессивный JPEG сюда нельзя: DCTDecode в PDF рассчитан на базовый,
 * прогрессивный часть просмотрщиков не покажет. За этим следит
 * SharePhotoResolver, который кодирует через GD базовым.
 *
 * Водяной знак рисуется поверх фотографии средствами самого PDF, а не
 * впекается в JPEG. Так строчка остаётся резкой на любом увеличении, а
 * фотография не перекодируется ради подписи — байты по-прежнему уходят как
 * есть. Кириллицы в знаке нет намеренно: номер, дата и адрес сайта
 * укладываются в WinAnsi, и встроенный Helvetica обходится без внедрения
 * шрифта.
 */
final class PdfWriter
{
    /**
     * Разрешение, в котором фотография кладётся на страницу.
     *
     * Точка PDF — 1/72 дюйма. При 72 точках на дюйм страница под фото в 1600
     * пикселей вышла бы шириной 56 см, при 150 — 27 см, то есть примерно A4.
     * На вид в просмотрщике это не влияет (он всё равно масштабирует под окно),
     * зато печать и предпросмотр в мессенджере ведут себя предсказуемо.
     */
    public const DPI = 150;

    /**
     * Высота полосы знака — доля высоты страницы, с потолком и полом.
     *
     * Доля нужна, чтобы полоса выглядела одинаково узкой на кадрах разных
     * пропорций; границы — чтобы на очень вытянутом кадре она не выродилась
     * в нитку и не разъехалась в плашку.
     */
    private const STAMP_RATIO = 0.038;

    private const STAMP_MIN = 10.0;

    private const STAMP_MAX = 20.0;

    /**
     * Ширины букв Helvetica (тысячные доли кегля), по три цифры на символ,
     * начиная с пробела.
     *
     * Нужны, чтобы поставить строку по центру полосы: PDF сам ничего не
     * центрирует, он рисует текст от заданной точки. Таблица короткая
     * намеренно — в знаке только цифры, латиница и разделитель.
     */
    private const HELVETICA_WIDTHS =
        '278278355556556889667191333333389584278333278278556556556556'
        .'556556556556556556278278584584584556101566766772272266761177'
        .'872227850066755683372277866777872266761172266794466766761127'
        .'827827846955633355655650055655627855655622222250022283355655'
        .'6556556333500278556500722500500500334260334584';

    /**
     * @param  list<array{path: string, width: int, height: int}>  $photos
     * @param  string|null  $stamp  строка водяного знака; null — без знака
     */
    public function write(array $photos, ?string $stamp = null): string
    {
        if ($photos === []) {
            throw new RuntimeException('Для PDF не выбрано ни одной фотографии.');
        }

        $objects = [];
        $pageRefs = [];

        // 1 — каталог, 2 — дерево страниц; дальше по три объекта на фотографию,
        // а шрифт и прозрачность знака общие и лежат последними
        $fontId = 3 + count($photos) * 3;
        $alphaId = $fontId + 1;

        foreach ($photos as $index => $photo) {
            $pageId = 3 + $index * 3;
            $contentId = $pageId + 1;
            $imageId = $pageId + 2;

            $bytes = @file_get_contents($photo['path']);

            if ($bytes === false) {
                throw new RuntimeException("Не удалось прочитать {$photo['path']}");
            }

            $width = round($photo['width'] * 72 / self::DPI, 2);
            $height = round($photo['height'] * 72 / self::DPI, 2);

            $pageRefs[] = $pageId.' 0 R';

            $resources = sprintf('/XObject << /Im0 %d 0 R >>', $imageId);

            if ($stamp !== null) {
                $resources .= sprintf(
                    ' /Font << /F0 %d 0 R >> /ExtGState << /GS0 %d 0 R >>',
                    $fontId,
                    $alphaId
                );
            }

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '
                .'/Resources << %s >> /Contents %d 0 R >>',
                $this->number($width),
                $this->number($height),
                $resources,
                $contentId
            );

            // картинка растягивается ровно на страницу: поля отсутствуют
            $content = sprintf('q %s 0 0 %s 0 0 cm /Im0 Do Q', $this->number($width), $this->number($height));

            if ($stamp !== null) {
                $content .= "\n".$this->stampContent($stamp, $width, $height);
            }

            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );

            $objects[$imageId] = sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s '
                ."/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $photo['width'],
                $photo['height'],
                $this->colorSpace($bytes),
                strlen($bytes),
                $bytes
            );
        }

        if ($stamp !== null) {
            $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                .'/Encoding /WinAnsiEncoding >>';
            // ca — прозрачность заливки: полоса просвечивает, буквы по ней нет
            $objects[$alphaId] = '<< /Type /ExtGState /ca 0.42 >>';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $pageRefs),
            count($photos)
        );

        return $this->assemble($objects);
    }

    /**
     * Полоса знака вдоль нижнего края страницы и текст по ней.
     *
     * Полоса рисуется внутри q…Q с прозрачностью, текст — снаружи: иначе
     * буквы выцвели бы вместе с фоном и на светлом кадре пропали.
     */
    private function stampContent(string $stamp, float $width, float $height): string
    {
        $bar = min(self::STAMP_MAX, max(self::STAMP_MIN, $height * self::STAMP_RATIO));
        $font = round($bar * 0.55, 2);
        $padding = round($bar * 0.6, 2);
        // высота прописной буквы Helvetica — около 0.72 кегля
        $baseline = round(($bar - $font * 0.72) / 2, 2);
        $text = $this->escape($stamp);
        // на очень узком кадре строка шире полосы — прижимаем к отступу,
        // иначе центрирование увело бы её левее края страницы
        $left = round(max($padding, ($width - $this->textWidth($stamp, $font)) / 2), 2);

        return sprintf(
            'q /GS0 gs 0.12 0.12 0.12 rg 0 0 %s %s re f Q'
            ."\nBT /F0 %s Tf 1 1 1 rg %s %s Td (%s) Tj ET",
            $this->number($width),
            $this->number($bar),
            $this->number($font),
            $this->number($left),
            $this->number($baseline),
            $text
        );
    }

    /** Ширина строки в точках при заданном кегле. */
    private function textWidth(string $text, float $font): float
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        $sum = 0;

        foreach (str_split($encoded === false ? '' : $encoded) as $char) {
            $code = ord($char);
            $index = ($code - 32) * 3;

            // точка-разделитель (0xB7) в таблицу не входит: по ширине как пробел
            $sum += $code === 0xB7
                ? 278
                : (int) (substr(self::HELVETICA_WIDTHS, $index, 3) ?: '556');
        }

        return $sum * $font / 1000;
    }

    /**
     * Текст в PDF: кодировка WinAnsi и экранирование скобок.
     *
     * Всё, что в WinAnsi не влезло, выкидывается: строку знака собирает
     * SharePdfBuilder из цифр и латиницы, и молча испорченная кодировка
     * страшнее пропавшего символа.
     */
    private function escape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);

        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '', ' '],
            $encoded === false ? '' : $encoded
        );
    }

    /** @param array<int, string> $objects */
    private function assemble(array $objects): string
    {
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $count = count($objects) + 1;
        $xrefAt = strlen($pdf);

        $pdf .= "xref\n0 ".$count."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefAt."\n%%EOF\n";

        return $pdf;
    }

    /**
     * Цветовая модель берётся из самого JPEG: у серых снимков один канал,
     * и объявить им DeviceRGB значит получить мусор вместо картинки.
     */
    private function colorSpace(string $jpeg): string
    {
        $length = strlen($jpeg);
        $offset = 2;

        while ($offset + 3 < $length) {
            if ($jpeg[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($jpeg[$offset + 1]);
            $size = (ord($jpeg[$offset + 2]) << 8) + ord($jpeg[$offset + 3]);

            // SOF0/1/2/3, 5-7, 9-11, 13-15 — начало кадра, там же число каналов
            if (($marker >= 0xC0 && $marker <= 0xCF)
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                $components = ord($jpeg[$offset + 9]);

                return match ($components) {
                    1 => 'DeviceGray',
                    4 => 'DeviceCMYK',
                    default => 'DeviceRGB',
                };
            }

            $offset += 2 + $size;
        }

        return 'DeviceRGB';
    }

    /** PDF не понимает экспоненциальную запись и запятую как разделитель. */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
