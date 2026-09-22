<?php

namespace App\Billing\Export;

use App\Billing\ManagerLedger;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Offers\Deal;
use App\Users\User;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Выгрузка менеджеру для его бухгалтера: сделки за период — цена, счёт, оплачено, вознаграждение, выплачено. */
final class ManagerStatement
{
    public function write(User $manager, CarbonInterface $from, CarbonInterface $to, string $path): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Сделки');
        $sheet->fromArray(['Дата', '№ предложения', 'Транспортное средство', 'Цена', 'Счёт', 'Оплачено', 'Агентское вознаграждение', 'Выплачено', 'Состояние'], null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $rows = (new ManagerLedger($manager))->dealsBetween($from, $to)->map(function (Deal $d) {
            $issued = $d->invoices->reject(fn ($i) => $i->isOwed());
            $paid = $issued->sum(fn ($i) => $i->allPayments->where('state', PaymentState::Confirmed)->where('source', '!=', PaymentSource::Offset)->sum('amount'));
            $fee = $d->agentFee;

            return [
                $d->created_at->format('d.m.Y'), $d->offer->number, $d->offer->titleWithYear(), $d->amount,
                $issued->map(fn ($i) => $i->label())->implode(', '), $paid,
                $d->showsCommission() ? $d->commission : null, $fee?->paid ?: ($d->withholds() && $d->showsCommission() ? $d->commission : 0),
                $d->commissionState()->label() ?: $d->state->label(),
            ];
        })->all();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
            $n = count($rows) + 1;
            $sheet->getStyle("D2:D{$n}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("F2:H{$n}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (['A' => 12, 'B' => 14, 'C' => 32, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 22, 'H' => 14, 'I' => 20] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A2');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
