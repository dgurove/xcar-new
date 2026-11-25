<?php

namespace App\Offers\Listeners;

use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use App\Offers\Deal;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * «Для менеджера сделка закрыта» (25.11.2026, `Deal::syncManagerDone`): пересчёт после того, что её меняет — вход в шаг продажи,
 * счёт выставлен или аннулирован, оплата записана или снята. После коммита: считается по свежим данным.
 */
final class SyncManagerDone
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            StageEntered::class => 'entered',
            PaymentRecorded::class => 'invoice',
            InvoiceIssued::class => 'invoice',
            InvoiceVoided::class => 'invoice',
            PaymentVoided::class => 'payment',
        ];
    }

    public function entered(StageEntered $e): void
    {
        if ($e->track === Track::Sale && ($id = ($e->deal ?? $e->offer->deal)?->id)) {
            self::later($id);
        }
    }

    public function invoice(object $e): void
    {
        $e->invoice->deal_id && self::later($e->invoice->deal_id);
    }

    public function payment(PaymentVoided $e): void
    {
        ($id = $e->payment->invoice?->deal_id) && self::later($id);
    }

    private static function later(int $id): void
    {
        DB::afterCommit(fn () => rescue(fn () => Deal::find($id)?->syncManagerDone(), report: true));
    }
}
