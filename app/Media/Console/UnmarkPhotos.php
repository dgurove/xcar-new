<?php

namespace App\Media\Console;

use App\Media\Actions\UnmarkPhoto;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use Illuminate\Console\Command;
use Throwable;

/** Снять чужой знак площадки с уже загруженных фото предложений (новые снимает приём фото сам); `--undo` — вернуть. */
class UnmarkPhotos extends Command
{
    protected $signature = 'media:unmark {offers* : номера или id предложений} {--undo : вернуть кадры со знаком}';

    protected $description = 'Снять водяной знак площадки (Мигторг) с фото предложений';

    public function handle(UnmarkPhoto $unmark): int
    {
        foreach ($this->argument('offers') as $ref) {
            $offer = OfferNumber::find($ref) ?? Offer::find($ref);
            if (! $offer) {
                $this->warn("Предложение {$ref} не найдено");

                continue;
            }
            $done = 0;
            $photos = $offer->photos();
            foreach ($photos as $media) {
                try {
                    $done += $this->option('undo') ? (int) $unmark->undo($media) : (int) (bool) $unmark($media);
                } catch (Throwable $e) {
                    $this->warn("Кадр {$media->id}: {$e->getMessage()}");
                }
            }
            $this->info(($this->option('undo') ? 'Возвращено' : 'Снято')." {$done} из {$photos->count()} — предложение {$ref}");
        }

        return self::SUCCESS;
    }
}
