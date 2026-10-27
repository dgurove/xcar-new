<?php

namespace App\Http\Logistics;

use App\Logistics\Transport;
use App\Support\Detail;
use Illuminate\Http\Request;
use App\Support\Table\Column;
use App\Support\Table\Table;

/**
 * Документы на вывоз (17.10.2026, владелец: «их оформляет Лиза, вводит трек-номер и отправляет СДЭКом исполнителю»):
 * модератору и админу — исполнители без печати, кому ещё не отправили, сверху; ниже — отправленные за две недели (идут
 * или получены). Строка — машина, кому и куда (пункт СДЭК); нажатие — карточка задачи с полем трека.
 */
class DocumentController
{
    public function index(Request $request, TransportController $transports)
    {
        $user = $request->user();
        abort_unless($user->sendsLogisticsDocs(), 404);
        $detail = Detail::of($request, fn (string $key) => ($t = Transport::where('docs_by_cdek', true)->find($key)) ? $transports->detail($t, 'documents') : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $with = ['car', 'offer.settlement', 'vehicle', 'executor', 'logist'];
        $waiting = Transport::awaitingDocs()->with($with)->oldest('assigned_at')->get();
        $sent = Transport::where('docs_by_cdek', true)->whereNotNull('docs_sent_at')->where('docs_sent_at', '>', now()->subDays(14))
            ->with($with)->latest('docs_sent_at')->get();

        return view('logistics.documents.index', ['detail' => $detail, 'waiting' => $waiting, 'sent' => $sent]);
    }

    /** Таблица «Документов» — одно описание на шапку и строки `x-logistics.doc-row`; дата справа — назначен или отправлены. */
    public static function table(bool $waiting): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true, cap: false),
            new Column('vendor', 'Вендор, № убытка', from: 'sm', rem: 10),
            new Column('to', 'Кому', from: 'sm', rem: 12),
            new Column('at', $waiting ? 'Назначен' : 'Отправлены', num: true, cell: 'nums whitespace-nowrap cell-dim', rem: 7),
        );
    }
}
