<?php

namespace App\Http\Live;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Замеры с телефонов (`resources/js/lib/perf.js`): сколько прошло от нажатия до отклика экрана и до готовых данных.
 * Строкой `tap` в журнал `perf-*.log`, рядом со страницами `ServerTiming`; сводка — `perf:pages`.
 */
class PerfController
{
    private const WHAT = ['tab', 'wheel', 'row', 'card', 'visit', 'back', 'window'];

    public function store(Request $request)
    {
        $screen = fn (string $key) => mb_substr(preg_replace('/[^\w\/\-\[\] .]/u', '', (string) $request->input($key)), 0, 80);
        $ms = fn (string $key) => $request->input($key) === null ? null : max(0, min(60_000, (int) $request->input($key)));
        if (in_array($request->input('what'), self::WHAT, true) && $ms('ready')) {
            Log::channel('perf')->info('tap', [
                'host' => $request->getHost(),
                'what' => $request->input('what'),
                'from' => $screen('from'),
                'to' => $screen('to'),
                'shown' => $ms('shown'),
                'ready' => $ms('ready'),
                // Сколько палец был на экране (нажатие → клик): входит в `shown` и `ready`, но это не ожидание.
                'finger' => $ms('finger'),
                'app' => $request->boolean('app'),
                'phone' => (bool) preg_match('/iPhone|Android.+Mobile/', (string) $request->userAgent()),
                'user' => $request->user()?->id,
            ]);
        }

        return response()->noContent();
    }
}
