<?php

namespace App\Media\Jobs;

use App\Media\Actions\StampPhoto;
use App\Media\Actions\UnstampPhoto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\HasMedia;

/**
 * Тумблер «Запретить шеринг» переключили у оффера или машины закупки: все
 * кадры (и скрытые тоже) под знак или обратно. Берётся текущее значение
 * тумблера, а не то, с которым задачу ставили: переключили дважды подряд —
 * выиграет последнее.
 */
final class StampPhotos implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public HasMedia&Model $model)
    {
        $this->onConnection('database-long')->onQueue('long')->afterCommit();
    }

    public function handle(StampPhoto $stamp, UnstampPhoto $unstamp): void
    {
        $model = $this->model->fresh();
        if (! $model) {
            return;
        }
        foreach ($model->photos() as $media) {
            $model->share_locked ? $stamp($media) : $unstamp($media);
        }
    }
}
