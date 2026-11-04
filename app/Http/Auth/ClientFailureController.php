<?php

namespace App\Http\Auth;

use App\Telegram\Messages\ClientFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * «Не получилось» на экране — с причиной (04.11.2026: Сергей на парковке нажимал «Ответить», видел «Не получилось», а
 * следов на сервере не было — 4xx не отчитываются, логи выкладка стирает). `net.js` шлёт beacon: адрес, статус, фрейм,
 * куда увели; сюда — свой лог и владельцу в Telegram (`ClientFailed`).
 */
class ClientFailureController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|string|max:500',
            'status' => 'nullable|integer',
            'frame' => 'nullable|string|max:60',
            'redirected' => 'nullable|string|max:500',
            'kind' => 'nullable|string|max:20',
        ]);
        $facts = [...$data, 'user' => $request->user()?->id, 'host' => $request->getHost(), 'ua' => $request->userAgent()];
        Log::channel('share')->warning('client: '.($data['kind'] ?? 'frame').' '.$data['url'].' → '.($data['status'] ?? '?'), $facts);
        ClientFailed::report($request, $data);

        return response()->noContent();
    }
}
