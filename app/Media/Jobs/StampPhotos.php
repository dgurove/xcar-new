<?php

namespace App\Media\Jobs;

use App\Cars\Car;
use App\Media\Actions\StampPhoto;
use App\Media\Actions\UnstampPhoto;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Тумблер «Запретить шеринг» переключили у оффера или машины закупки: все
 * кадры машины (`Cars\Car`, и скрытые тоже) под знак или обратно. Берётся текущее значение
 * тумблера, а не то, с которым задачу ставили: переключили дважды подряд —
 * выиграет последнее. Один нечитаемый кадр не останавливает остальные.
 */
final class StampPhotos implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    /** Замок не вечный: упавшая постановка не запирает модель навсегда. */
    public int $uniqueFor = 3600;

    /** @param  Model  $model  машина или её дорожка (предложение, лот закупки) */
    public function __construct(public Model $model)
    {
        // Очередь `files` — человек смотрит на кадры (14.10.2026: в `long` за выкачкой Мигторга знак ждал час).
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    /** Одна задача на модель в очереди: значение тумблера берётся при выполнении, вторая ничего не добавит. */
    public function uniqueId(): string
    {
        return $this->model::class.':'.$this->model->getKey();
    }

    public function handle(StampPhoto $stamp, UnstampPhoto $unstamp): void
    {
        $model = $this->model->fresh();
        $car = $model instanceof Car ? $model : $model?->carRecord();
        if (! $car) {
            return;
        }
        $locked = $car->shareLocked();
        foreach ($car->photos() as $media) {
            try {
                $locked ? $stamp($media) : $unstamp($media);
            } catch (Throwable $e) {
                Log::warning('Водяной знак: кадр пропущен', ['media' => $media->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
