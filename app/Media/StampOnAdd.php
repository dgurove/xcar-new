<?php

namespace App\Media;

use App\Media\Actions\StampPhoto;
use App\Offers\Offer;
use App\Purchases\Car;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * Новый кадр у оффера или машины закупки с запретом шеринга клеймится сразу, в том же запросе:
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
        $model = $media->model;
        if ($media->collection_name === 'photos' && ($model instanceof Offer || $model instanceof Car) && $model->share_locked) {
            ($this->stamp)($media, conversions: false);
        }
    }
}
