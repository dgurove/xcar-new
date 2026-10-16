<?php

namespace App\Billing\Export;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Отчёт-акт реестра файлом Excel — той же формой, что PDF (Питер, 09.10.2026: Альфа правит отчёт-акт красным прямо в
 * xlsx и присылает назад). Строка — начисление машины: VIN, даты приёма и передачи, убыток, полис, госномер, марка,
 * услуга, сутки, цена, стоимость; внизу итог.
 */
final class RegistryExport
{
    public const HEAD = ['№ п/п', 'VIN ТС', 'Дата принятия ТС к хранению', 'Дата передачи ТС после хранения', '№ убытка', '№ полиса',
        'Государственный регистрационный знак ТС', 'Марка ТС', 'Наименование услуги', 'Кол-во сут.', 'Цена', 'Стоимость оказания услуги'];

    public static function write(Invoice $invoice, string $path): void
    {
        $invoice->loadMissing(['charges.vehicle', 'charges.vehicle.vendor']);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Отчёт-акт');
        $writer->addRow(Row::fromValues(self::HEAD));
        $n = 0;
        foreach ($invoice->charges->whereNotNull('vehicle_id')->groupBy('vehicle_id') as $rows) {
            $n++;
            foreach ($rows as $c) {
                $v = $c->vehicle;
                $storage = $c->kind === ChargeKind::Storage;
                $service = $storage ? ($v?->vendor?->storageServiceTitle() ?? 'Хранение ТС') : $c->title;
                $writer->addRow(Row::fromValues([$n, (string) $v?->vin, (string) $v?->accepted_at?->format('d.m.Y'), (string) $v?->released_at?->format('d.m.Y'),
                    (string) $v?->ref, (string) $v?->policy_no, (string) $v?->plate, (string) $v?->carRecord()->title(),
                    $service, $storage ? (int) $c->qty : (float) $c->qty, (float) $c->price, (float) $c->amount]));
            }
        }
        $writer->addRow(Row::fromValues(['', '', '', '', '', '', '', '', 'Итого, ТС: '.$n, '', '', (float) $invoice->charges->sum('amount')]));
        $writer->close();
    }
}
