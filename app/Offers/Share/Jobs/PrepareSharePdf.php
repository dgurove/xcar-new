<?php

namespace App\Offers\Share\Jobs;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * PDF по умолчанию — заранее (21.10.2026, владелец: «предформировать, чтобы бот не тратил время»): первые 10 видимых
 * кадров без знака (`SharePdf::prepared`). Ставят публикация (`OfferPublished`) и смена кадров у открытого (`CarChanged`
 * с `photos`); берётся то, что есть при выполнении. Шеринг закрыт или кадров нет — нечего собирать.
 */
final class PrepareSharePdf implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct(public int $offerId)
    {
        // Очередь `files`, как знак на кадрах: человек ждёт кадры, а не выкачку Мигторга.
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->offerId;
    }

    public function handle(SharePdf $pdf): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer || $offer->state !== OfferState::Open || $offer->share_locked || $offer->visiblePhotos()->isEmpty()) {
            return;
        }
        $pdf->prepared(Subject::offer($offer));
    }
}
