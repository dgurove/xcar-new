<?php

namespace App\Offers\Jobs;

use App\Offers\PlannedLetter;
use App\Offers\PlannedLetters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Запланированное письмо уходит (`Offers\PlannedLetters::release`): повторяется, после последней неудачи — «не ушло», громко. */
final class ReleasePlannedLetter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public int $planned) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(PlannedLetters $letters): void
    {
        $letters->release($this->planned);
    }

    public function failed(Throwable $e): void
    {
        if ($planned = PlannedLetter::find($this->planned)) {
            app(PlannedLetters::class)->fail($planned, 'ошибка при отправке: '.mb_substr($e->getMessage(), 0, 200));
        }
    }
}
