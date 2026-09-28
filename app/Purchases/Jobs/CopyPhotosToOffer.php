<?php

namespace App\Purchases\Jobs;

use App\Media\Watermark;
use App\Offers\Offer;
use App\Purchases\Car;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Фото ТС закупки → её предложение: оригиналы по порядку, со скрытыми кадрами и отпечатком. Кадр с водяным
 * знаком берётся чистым (`Watermark::cleanPath`) — клеймо на предложении поставит `StampOnAdd` по его же
 * запрету шеринга, конверсии строит spatie. Повтор безвреден: уже перенесённый отпечаток пропускается.
 */
final class CopyPhotosToOffer implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $carId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(): void
    {
        $car = Car::with('media')->find($this->carId);
        $offer = $car?->offer_id ? Offer::find($car->offer_id) : null;
        if (! $offer) {
            return;
        }
        foreach ($car->photos() as $media) {
            $clean = Watermark::cleanPath($media);
            $source = $media->getCustomProperty('watermarked', false) && is_file($clean) ? $clean : $media->getPath();
            if (! is_file($source)) {
                continue;
            }
            $props = array_diff_key($media->custom_properties, ['watermarked' => true]);
            $props['sha'] ??= hash_file('sha256', $source);
            if ($offer->hasFile($props['sha'])) {
                continue;
            }
            $offer->addMedia($source)->preservingOriginal()->usingFileName($media->file_name)->usingName($media->name)
                ->withCustomProperties($props)->toMediaCollection('photos');
        }
    }
}
