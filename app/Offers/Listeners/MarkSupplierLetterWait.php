<?php

namespace App\Offers\Listeners;

use App\Offers\SupplierLetterWait;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Встали на «Ждём письмо поставщика», а письмо уже пришло (обычно оно и завело машину) — ход наш, «Спросить менеджера»
 * (`SupplierLetterWait::mark`); дальше — кнопкой сотрудника.
 */
final class MarkSupplierLetterWait
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! SupplierLetterWait::exitOf($e->to)) {
            return;
        }
        $offer = $e->offer->fresh();
        if ($offer?->position(Track::Sale)?->stage_id === $e->to->id) {
            SupplierLetterWait::mark($offer);
        }
    }
}
