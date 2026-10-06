<?php

namespace App\Media\Console;

use App\Media\Actions\UnmarkPhoto;
use App\Media\PhotoIngest;
use App\Media\Watermark;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Park\Vehicle;
use App\Purchases\Car;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Листы со снимками, что легли кадрами до общей обработки (06.10.2026: Мигторг отдаёт часть лотов листами A4 с белыми
 * полями и подписью, обрезка жила только в почте). Режет сохранённый файл тем же шагом приёма (`PhotoIngest::pages`):
 * первый снимок — на место кадра (порядок, «скрыт», главный, наш знак — `UnmarkPhoto::swap`), остальные — новыми
 * кадрами той же машины. Без `--apply` — только список. Оригиналы у Мигторга заново не качаются: их клиент ждёт секунду
 * на запрос, а сохранённый лист 1600 px режется почти без потерь. Повторный прогон ничего не находит — снимок не лист.
 */
class CropPages extends Command
{
    protected $signature = 'media:crop-pages {--apply : обрезать, без него — только список} {--offer=* : номера или id предложений}';

    protected $description = 'Обрезать листы со снимками (белые поля, подпись) у уже загруженных фото';

    /** Свойства кадра, что переходят к вырезкам с того же листа. */
    private const KEEP = ['migtorg', 'stage', 'source', 'offer'];

    public function handle(PhotoIngest $ingest, UnmarkPhoto $unmark): int
    {
        $apply = (bool) $this->option('apply');
        [$found, $added] = [0, 0];
        foreach ($this->photos() as $media) {
            $path = $media->getCustomProperty('watermarked', false) ? Watermark::cleanPath($media) : $media->getPath();
            if (! is_file($path)) {
                continue;
            }
            $bands = $ingest->pages($path);
            if (! $bands) {
                continue;
            }
            $found++;
            $this->line(class_basename($media->model_type)." {$media->model_id}, кадр {$media->id}: снимков ".count($bands));
            try {
                if ($apply) {
                    $unmark->swap($media, $bands[0], $ingest);
                    $media->setCustomProperty('page_sha', $media->getCustomProperty('sha'))->save();
                    $keep = array_intersect_key($media->custom_properties, array_flip(self::KEEP));
                    foreach (array_slice($bands, 1) as $n => $band) {
                        $ingest->add($media->model, 'photos', $band, pathinfo($media->file_name, PATHINFO_FILENAME).'-'.($n + 2).'.jpg',
                            $keep + ['page_sha' => $media->getCustomProperty('sha')]);
                        $added++;
                    }
                }
            } catch (Throwable $e) {
                $this->warn("Кадр {$media->id}: {$e->getMessage()}");
            } finally {
                foreach ($bands as $band) {
                    @unlink($band);
                }
            }
        }
        $this->info(($apply ? 'Обрезано' : 'Листов найдено')." {$found}".($added ? ", новых кадров {$added}" : ''));

        return self::SUCCESS;
    }

    /** @return iterable<Media> */
    private function photos(): iterable
    {
        $offers = collect($this->option('offer'))->map(fn ($ref) => OfferNumber::find($ref) ?? Offer::find($ref))->filter();
        if ($offers->isNotEmpty()) {
            return $offers->flatMap(fn (Offer $o) => $o->photos());
        }

        return Media::where('collection_name', 'photos')->whereIn('model_type', [Offer::class, Vehicle::class, Car::class])->lazyById(200);
    }
}
