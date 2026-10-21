<?php

namespace App\Offers\Export;

use App\Support\OfficePreview;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ответ окна «Выгрузка» по выбранным столбцам (`cols=claim,created`): `format=json` — строки для образца и буфера (окно
 * берёт их заранее, копирует в том же нажатии — иначе iOS не пускает в буфер), иначе xlsx. Файл открывает
 * шторка документов (`x-ui.doc`): `preview=1` — HTML-видом, `inline=1` — во встроенном браузере приложения (иначе
 * Quick Look без выхода).
 */
final class OffersXlsx
{
    public static function respond(Request $request, User $user, string $list, Builder $q, string $name)
    {
        $keys = OfferColumns::pick($list, $user, array_filter(explode(',', (string) $request->query('cols'))));
        $offers = OfferColumns::load($list, $q, $keys)->get();
        if ($request->query('format') === 'json') {
            return response()->json(['cols' => $keys, 'rows' => OfferColumns::rows($offers, $keys)]);
        }
        $file = $name.' '.now()->format('d.m.Y').'.xlsx';
        // Файл с хвостом: шторка на телефоне просит файл и его вид разом — второй ответ не стирает файл из-под первого.
        $disk = Storage::disk('cache');
        $disk->makeDirectory('tmp');
        $path = $disk->path('tmp/offers-'.bin2hex(random_bytes(6)).'.xlsx');
        self::write(OfferColumns::head($keys), OfferColumns::rows($offers, $keys), $path);
        if ($request->boolean('preview')) {
            try {
                return OfficePreview::response($path, $file);
            } finally {
                @unlink($path);
            }
        }

        return response()->download($path, $file, [], $request->boolean('inline') ? 'inline' : 'attachment')->deleteFileAfterSend();
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
