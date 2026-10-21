<?php

namespace App\Offers\Export;

use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ответ окна «Выгрузка»: `format=json` — все строки по всем столбцам человека (окно строит образец и буфер у себя, в том
 * же нажатии — иначе iOS не пускает в буфер), иначе xlsx выбранных столбцов (`cols=claim,created`).
 */
final class OffersXlsx
{
    public static function respond(Request $request, User $user, string $list, Builder $q, string $name)
    {
        $offers = OfferColumns::load($q)->get();
        if ($request->query('format') === 'json') {
            $keys = array_column(OfferColumns::for($list, $user), 'key');

            return response()->json(['cols' => $keys, 'rows' => OfferColumns::rows($offers, $keys)]);
        }
        $keys = OfferColumns::pick($list, $user, array_filter(explode(',', (string) $request->query('cols'))));

        return response()->streamDownload(fn () => self::write(OfferColumns::head($keys), OfferColumns::rows($offers, $keys), 'php://output'),
            $name.' '.now()->format('d.m.Y').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param list<string> $head @param list<list<string|int|null>> $rows */
    public static function write(array $head, array $rows, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Предложения');
        $writer->addRow(Row::fromValues($head));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($x) => $x ?? '', $row)));
        }
        $writer->close();
    }
}
