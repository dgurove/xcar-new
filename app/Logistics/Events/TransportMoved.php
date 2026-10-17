<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ход задачи: `agreed` — созвонились (или перенесли), `missed` — не дозвонились, `docs_sent` — документы ушли СДЭКом,
 * `docs_received` — исполнитель их получил, `picked` — «Забрал», `delivered` — «Привёз», `cancelled` — отменили.
 * Слушают продажа (место машины, шаг получения), гараж, уведомления и живое обновление.
 */
final class TransportMoved
{
    use Dispatchable;

    public function __construct(public Transport $transport, public ?User $by = null, public string $step = '') {}
}
