<?php

namespace App\Park;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * «Наличие» файлом — колонками таблицы, которую парковка вела до приложения (Питер, 09.10.2026): марка и модель, VIN,
 * год, цвет, СТС, (Э)ПТС, госномер, приём, выбытие («стоит», пока стоит), вендор, убыток, полис, стоимость, парковка.
 * Ею сверяют приёмы и выбытия месяца с вендором — таблицу больше не ведут руками.
 */
final class VehicleExport
{
    public const HEAD = ['Марка/модель ТС', 'Vin номер', 'Год выпуска', 'Цвет', 'СТС', '(Э)ПТС', 'Рег.номер', 'Факт.дата прибытия', 'Дата отгрузки ТС',
        'Страховая', 'Номер убытка/полиса', '№ Полиса', 'Заявленная стоимость ТС', 'Парковка'];

    /** @param  Collection<int, Vehicle>  $vehicles */
    public static function write(Collection $vehicles, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('ТС');
        $writer->addRow(Row::fromValues(self::HEAD));
        foreach ($vehicles as $v) {
            $writer->addRow(Row::fromValues(array_map(fn ($x) => $x ?? '', self::row($v))));
        }
        $writer->close();
    }

    /** @return list<string|int|null> */
    public static function row(Vehicle $v): array
    {
        return [
            trim(($v->brand?->name ?? '').' '.($v->model?->name ?? '')) ?: null, $v->vin, $v->year, $v->color, $v->sts, $v->pts, $v->plate,
            $v->accepted_at?->format('d.m.Y'),
            $v->released_at?->format('d.m.Y') ?? ($v->state === VehicleState::Stored ? 'стоит' : $v->state->label()),
            $v->vendor?->name, $v->ref, $v->policy_no, $v->value, $v->yard?->name,
        ];
    }
}
