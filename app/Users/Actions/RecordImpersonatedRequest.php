<?php

namespace App\Users\Actions;

use App\Users\Impersonation;
use Illuminate\Http\Request;

/**
 * Запись, сделанная админом за человека, — строкой в журнал входа. Служебное (печатает, прочитал колокольчик,
 * пуш, живые счётчики, вход и выход) не пишется: иначе подтверждения и оплаты тонули бы в сотнях «печатает».
 */
final class RecordImpersonatedRequest
{
    private const SERVICE = ['chats/*/typing', 'account/notifications/*', 'push/*', 'live/*', 'login/*', 'logout'];

    public function __invoke(Impersonation $as, Request $request, int $status): void
    {
        if ($request->isMethodSafe() || $request->is(...self::SERVICE)) {
            return;
        }
        $as->actions()->create([
            'method' => $request->method(),
            'path' => mb_substr('/'.$request->path(), 0, 500),
            'status' => $status,
            'created_at' => now(),
        ]);
    }
}
