<?php

namespace App\Media\Console;

use App\Media\Actions\StampPhoto;
use App\Media\Actions\UnstampPhoto;
use App\Offers\Offer;
use App\Purchases\Car;
use Illuminate\Console\Command;
use Throwable;

/** Знак перерисовали (resources/images/watermark.png) — перебить его на всех кадрах с запретом шеринга. */
class Restamp extends Command
{
    protected $signature = 'media:restamp';

    protected $description = 'Снять и заново положить водяной знак на все кадры офферов и машин закупок с запретом шеринга';

    public function handle(StampPhoto $stamp, UnstampPhoto $unstamp): int
    {
        $n = 0;
        foreach ([Offer::class, Car::class] as $class) {
            $class::where('share_locked', true)->with('media')->lazy()->each(function ($model) use ($stamp, $unstamp, &$n) {
                foreach ($model->photos() as $media) {
                    try {
                        $unstamp($media);
                        $stamp($media);
                        $n++;
                    } catch (Throwable $e) {
                        $this->warn("Кадр {$media->id}: {$e->getMessage()}");
                    }
                }
            });
        }
        $this->info("Перебито кадров: {$n}");

        return self::SUCCESS;
    }
}
