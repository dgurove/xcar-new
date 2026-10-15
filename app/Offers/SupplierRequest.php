<?php

namespace App\Offers;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Заявка поставщику на выкуп пачкой (09.10.2026, владелец: у Каркаде каждый раз свой лот — приняли машины в сделки,
 * отправили одной заявкой, ответ через несколько дней). Список — все сделки вендора, что ждут заявки
 * (`Deal::awaitingRequest`); цена — закупочная сделки, то есть итоговая цена контрпредложения поставщика.
 */
final class SupplierRequest
{
    private const HEAD = ['№', 'ДЛ', 'Марка', 'Модель', 'Год', 'VIN', 'Город', 'Цена'];

    /** @param  Collection<int, Deal>  $deals */
    public function __construct(public readonly Collection $deals) {}

    public static function for(int $vendorId): self
    {
        return new self(Deal::awaitingRequest($vendorId));
    }

    public function filename(): string
    {
        return 'zayavka-'.now()->format('Y-m-d').'.xlsx';
    }

    /** @return list<array> */
    public function rows(): array
    {
        return $this->deals->values()->map(fn (Deal $d, int $i) => [$i + 1, $d->offer->leaseRef() ?? $d->offer->claim_ref, $d->offer->brand?->name, $d->offer->model?->name,
            $d->offer->year, $d->offer->vin, $d->offer->settlement?->name, $d->supplierCost()])->all();
    }

    /** Файл заявки: один лист, шапка жирным, цены разрядами. */
    public function xlsx(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Заявка');
        $sheet->fromArray(self::HEAD, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $rows = $this->rows();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
            $sheet->getStyle('H2:H'.(count($rows) + 1))->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (['A' => 5, 'B' => 16, 'C' => 14, 'D' => 18, 'E' => 7, 'F' => 20, 'G' => 18, 'H' => 13] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A2');
        $out = fopen('php://memory', 'r+');
        (new Xlsx($book))->save($out);
        $book->disconnectWorksheets();
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
