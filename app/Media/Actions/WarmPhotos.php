<?php

namespace App\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Карточку из холодного слоя открыли — конверсии досчитываются в очереди, через минуту кадры снова лёгкие. */
final class WarmPhotos
{
    public function __construct(private FileManipulator $files) {}

    /** @param  Model  $model  модель с медиа или дорожка машины (`Cars\OnCar`: её кадры — у машины) */
    public function __invoke(Model $model): int
    {
        $cold = $model->getMedia('photos')->filter(fn (Media $m) => ! array_filter((array) $m->generated_conversions));
        foreach ($cold as $media) {
            // Пока очередь считает, повторные показы карточки задач не добавляют.
            if (Cache::add("warm:{$media->id}", 1, 300)) {
                $this->files->createDerivedFiles($media, onlyMissing: true);
            }
        }

        return $cold->count();
    }
}
