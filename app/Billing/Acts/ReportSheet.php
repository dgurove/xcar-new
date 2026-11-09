<?php

namespace App\Billing\Acts;

use App\Support\TempFile;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Отчёт-акт (форма Альфы, `ActForm::Report`) — собирается кодом, а не прежним файлом страховой (09.11.2026, владелец: «как
 * нормально, по уму файл акта сделать, а то это пиздец»: в прежнем файле скрытые столбцы с формулами, фильтр в шапке, даты
 * «3/28/26», строки высотой в три текста, текст у нижнего края). Тексты — слово в слово прежние; вид — один шрифт (Times New Roman, как в прежних актах), таблица в
 * рамке, строки по высоте текста и посередине, даты «28.03.2026», суммы числами без формул, шапка таблицы повторяется на
 * каждой странице печати, лист — альбомный, по ширине страницы.
 */
final class ReportSheet
{
    /** Столбцы таблицы: подпись, ширина (знаков), выравнивание. */
    private const COLUMNS = [
        'A' => ['№', 5, Alignment::HORIZONTAL_CENTER],
        'B' => ['VIN ТС', 23, Alignment::HORIZONTAL_LEFT],
        'C' => ['Дата принятия ТС к хранению', 13, Alignment::HORIZONTAL_CENTER],
        'D' => ['Дата передачи ТС после хранения', 13, Alignment::HORIZONTAL_CENTER],
        'E' => ['№ убытка', 20, Alignment::HORIZONTAL_LEFT],
        'F' => ['№ полиса', 23, Alignment::HORIZONTAL_LEFT],
        'G' => ['Государственный регистрационный знак ТС', 18, Alignment::HORIZONTAL_LEFT],
        'H' => ['Марка ТС', 22, Alignment::HORIZONTAL_LEFT],
        'I' => ['Наименование услуги', 34, Alignment::HORIZONTAL_LEFT],
        'J' => ['Стоимость оказания услуги', 13, Alignment::HORIZONTAL_RIGHT],
    ];

    /** Столбцы с переносом текста — по ним считается высота строки. */
    private const WRAPS = ['H', 'I'];

    private const FONT = 'Times New Roman';

    private const SIZE = 10;

    /** Высота строки текста в пунктах (Times New Roman 10). */
    private const LINE = 13;

    /**
     * @param  array<string, mixed>  $vars  number, period, contract, preamble, ended, total_text, customer_short, self_short, self_signer
     * @param  list<array<string, mixed>>  $rows  vin, accepted, released, ref, policy, plate, car, service, amount
     */
    public static function build(array $vars, array $rows): string
    {
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(self::SIZE);
        $s = $book->getActiveSheet();
        $s->setTitle('Отчет-акт');
        $s->setShowGridlines(false);
        foreach (self::COLUMNS as $col => [, $width]) {
            $s->getColumnDimension($col)->setWidth($width);
        }
        $width = array_sum(array_column(self::COLUMNS, 1));
        $v = fn (string $k) => (string) ($vars[$k] ?? '');

        $r = 1;
        $r = self::line($s, $r, 'ОТЧЕТ-АКТ № '.$v('number'), $width, bold: true, center: true, size: 12);
        $r = self::line($s, $r, 'сдачи-приемки оказанных услуг за период с '.$v('period'), $width, center: true);
        $r = self::line($s, $r, 'к договору № '.$v('contract'), $width, center: true) + 1;
        $r = self::line($s, $r, $v('preamble'), $width) + 1;
        $r = self::line($s, $r, '1. Настоящий Отчет-Акт составлен за период с '.$v('period').', в котором Хранитель оказывал Поклажедателю услуги, '
            .'предусмотренные в Договоре. '.$v('ended').' Поклажедатель удостоверяет, что данные услуги оказаны Хранителем надлежащим образом и '
            .'подлежат оплате в установленном Договором порядке и сроки.', $width) + 1;

        // Таблица: шапка (повторяется на каждой печатной странице), строки, итог.
        $head = $r;
        foreach (self::COLUMNS as $col => [$title]) {
            $s->setCellValue($col.$r, $title);
        }
        $s->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
        $s->getStyle("A{$r}:J{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $s->getStyle("A{$r}:J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        // Высота шапки — по самому длинному заголовку с переносом по словам (жирный шире — запас; 09.11.2026: шапка обрезалась).
        $s->getRowDimension($r)->setRowHeight(self::LINE * max(array_map(fn ($c) => self::lines($c[0], $c[1], .82), self::COLUMNS)) + 6);
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        $r++;
        $first = $r;
        $sum = 0.0;
        foreach (array_values($rows) as $i => $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $sum += $amount;
            $s->setCellValue('A'.$r, $i + 1);
            foreach (['B' => 'vin', 'E' => 'ref', 'F' => 'policy', 'G' => 'plate', 'H' => 'car', 'I' => 'service'] as $col => $key) {
                $s->setCellValueExplicit($col.$r, (string) ($row[$key] ?? ''), DataType::TYPE_STRING);
            }
            foreach (['C' => 'accepted', 'D' => 'released'] as $col => $key) {
                if (($row[$key] ?? null) instanceof DateTimeInterface) {
                    $s->setCellValue($col.$r, ExcelDate::PHPToExcel($row[$key]));
                }
            }
            $s->setCellValue('J'.$r, $amount);
            // Высота — по самому длинному тексту строки (Excel не подбирает высоту сам, если в строке перенос).
            $lines = max(1, ...array_map(fn ($col) => self::lines((string) $s->getCell($col.$r)->getValue(), self::COLUMNS[$col][1]), self::WRAPS));
            $s->getRowDimension($r)->setRowHeight(self::LINE * $lines + 5);
            $r++;
        }
        $last = $r - 1;
        if ($last >= $first) {
            $s->getStyle("C{$first}:D{$last}")->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            $s->getStyle("J{$first}:J{$last}")->getNumberFormat()->setFormatCode('#,##0');
            $s->getStyle("A{$first}:J{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(false);
            // Переносятся только марка и услуга; коды (VIN, убыток, полис, госномер) — одной строкой всегда: не влез — шрифт
            // ужимается (09.11.2026: VIN «SADCA2BN1HA88621 / 1» на маке ломался на две строки).
            foreach (self::WRAPS as $col) {
                $s->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()->setWrapText(true);
            }
            foreach (['B', 'E', 'F', 'G'] as $col) {
                $s->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()->setShrinkToFit(true);
            }
            foreach (self::COLUMNS as $col => [, , $align]) {
                $s->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()->setHorizontal($align);
            }
        }
        // Итог — строкой «Итого:» через всю таблицу до суммы, справа, жирным (как в счетах и актах), сумма — под суммами.
        $s->mergeCells("A{$r}:I{$r}");
        $s->setCellValue('A'.$r, 'Итого:');
        $s->setCellValue('J'.$r, (float) ($vars['sum'] ?? $sum));
        $s->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
        $s->getStyle("A{$r}:J{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        $s->getStyle('J'.$r)->getNumberFormat()->setFormatCode('#,##0');
        $s->getRowDimension($r)->setRowHeight(self::LINE + 6);
        $s->getStyle("A{$head}:J{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $r += 2;

        $r = self::line($s, $r, '2. Итого общая сумма оказанных Хранителем и принятых Поклажедателем услуг, подлежащая оплате, составила '.$v('total_text').'.', $width);
        $r = self::line($s, $r, '3. Настоящий Отчет-Акт составлен в 2 (двух) идентичных экземплярах, имеющих равную юридическую силу, по 1 (одному) экземпляру для Хранителя и Поклажедателя.', $width);
        $r = self::line($s, $r, '4. Подписи сторон:', $width) + 1;

        // Подписи — две колонки: Поклажедатель слева (B–E), Хранитель справа (G–J).
        $pair = function (string $left, string $right, bool $bold = false) use ($s, &$r) {
            $s->mergeCells("B{$r}:E{$r}");
            $s->mergeCells("G{$r}:J{$r}");
            $s->setCellValue('B'.$r, $left);
            $s->setCellValue('G'.$r, $right);
            $s->getStyle("B{$r}:J{$r}")->getFont()->setBold($bold);
            $s->getRowDimension($r)->setRowHeight(self::LINE + 5);
            $r++;
        };
        $pair('Поклажедатель:', 'Хранитель:', true);
        $pair($v('customer_short'), $v('self_short'));
        $r++;
        $pair('_____________________ / _____________________ /', '_____________________ / '.($v('self_signer') ?: '_____________________').' /');
        $pair('М.п.', 'М.п.');
        $pair('«____» ____________________ 20___ г.', '«____» ____________________ 20___ г.');

        $setup = $s->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $setup->setPrintArea('A1:J'.($r - 1));
        $s->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $s->setSelectedCell('A1');

        $out = TempFile::path('act-', 'xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($out);

        return $out;
    }

    /** Абзац на всю ширину листа: одна ячейка A:J с переносом, высота — по числу строк текста. */
    private static function line(Worksheet $s, int $r, string $text, int $width, bool $bold = false, bool $center = false, int $size = self::SIZE): int
    {
        $s->mergeCells("A{$r}:J{$r}");
        $s->setCellValueExplicit('A'.$r, trim(preg_replace('/\s+/u', ' ', $text)), DataType::TYPE_STRING);
        $style = $s->getStyle("A{$r}:J{$r}");
        $style->getFont()->setBold($bold)->setSize($size);
        $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP)
            ->setHorizontal($center ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_JUSTIFY);
        $s->getRowDimension($r)->setRowHeight((self::LINE + ($size - self::SIZE)) * self::lines($text, $width) + 3);

        return $r + 1;
    }

    /**
     * Сколько строк займёт текст в ширине столбца — переносом по словам, как Excel; `$fit` — сколько знаков на единицу ширины
     * (обычный текст ~0,95, жирный уже ~0,82: Excel на маке рисует шире, высота с запасом лучше обрезанного текста).
     */
    private static function lines(string $text, int $chars, float $fit = .95): int
    {
        $per = max(1, (int) floor($chars * $fit));
        $lines = 0;
        foreach (explode("\n", trim($text)) as $part) {
            $n = 1;
            $len = 0;
            foreach (preg_split('/\s+/u', trim($part)) ?: [] as $word) {
                $w = mb_strlen($word);
                if ($len > 0 && $len + 1 + $w > $per) {
                    $n++;
                    $len = 0;
                }
                // Слово длиннее строки Excel режет — оно займёт несколько строк.
                $n += $w > $per ? (int) ceil($w / $per) - 1 : 0;
                $len = $len === 0 ? ($w > $per ? $w % $per : $w) : $len + 1 + $w;
            }
            $lines += $n;
        }

        return max(1, $lines);
    }
}
