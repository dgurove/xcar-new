<?php

namespace App\Http\Logistics;

use App\Logistics\Transport;
use App\Support\Detail;
use Illuminate\Http\Request;

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
        $detail = Detail::of($request, fn (string $key) => ($t = Transport::where('docs_by_cdek', true)->find($key)) ? $transports->detail($t) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $with = ['car', 'offer', 'vehicle', 'executorUser', 'coordinator'];
        $waiting = Transport::awaitingDocs()->with($with)->oldest('assigned_at')->get();
        $sent = Transport::where('docs_by_cdek', true)->whereNotNull('docs_sent_at')->where('docs_sent_at', '>', now()->subDays(14))
            ->with($with)->latest('docs_sent_at')->get();

        return view('logistics.documents.index', ['detail' => $detail, 'waiting' => $waiting, 'sent' => $sent]);
    }
}
