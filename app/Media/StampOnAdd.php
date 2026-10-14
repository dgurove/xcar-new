<?php

namespace App\Media;

use App\Cars\Car;
use App\Media\Actions\StampPhoto;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * Новый кадр машины, чья продажа или лот закупки запрещает шеринг, клеймится сразу, в том же запросе:
 * событие spatie стреляет до постановки конверсий, они строятся уже из
 * заклеймённого оригинала, и чистый файл в публичной зоне не появляется ни на
 * секунду. Одна дверь для всех путей — телефон, письма, что угодно дальше.
 */
final class StampOnAdd
{
    public function __construct(private StampPhoto $stamp) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;
        // Кадр машины — под знак, если её продажа (или лот закупки) запрещает шеринг (`Car::shareLocked`).
        $model = $media->model;
        if ($media->collection_name === 'photos' && $model instanceof Car && $model->shareLocked()) {
            ($this->stamp)($media, conversions: false);
        }
    }
}
