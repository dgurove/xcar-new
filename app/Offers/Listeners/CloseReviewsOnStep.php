<?php

namespace App\Offers\Listeners;

use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;

/**
 * Сотрудник сдвинул шаг сделки — ждущие разбора письма по ней разобраны (10.11.2026, начальник по Volvo 01527: письмо
 * «согласовали» пришло в 18:10, в 18:14 нажали «Поставщик согласовал», а «Показать менеджеру…» висело лаймом сверху окошка, пока
 * ждали ответа самого менеджера). Шаг сделал тот, кто письмо прочёл; менеджер свой шаг письма не гасит — он письма не видел.
 * Шаг из «Показать менеджеру» (`letter`) закрывает разбор сам (`Disclose`).
 */
final class CloseReviewsOnStep
{
    public function handle(StageEntered $e): void
    {
        $deal = $e->deal ?? $e->offer->deal;
        if ($e->track !== Track::Sale || $e->letter || ! $deal || ! $e->by?->isStaff()) {
            return;
        }
        $done = ReplyReview::pending()->where('deal_id', $deal->id)->update(['state' => ReplyReview::DONE, 'done_by' => $e->by->id, 'done_at' => now()]);
        $done && $e->offer->log(OfferEventType::Note, $e->by, ['text' => ($done > 1 ? 'Письма разобраны' : 'Письмо разобрано').' шагом «'.($e->exit?->label ?? $e->to->name).'»']);
    }
}
