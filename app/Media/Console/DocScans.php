<?php

namespace App\Media\Console;

use App\Mail\Attachment;
use App\Mail\Scan\AutoScan;
use App\Media\DocumentScan;
use App\Media\Watermark;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Сканы документов, что легли кадрами до `DocumentScan` (12.10.2026: заявка Альфы картинкой среди фото машины): в
 * документы — у живых ТС парковки и предложений; вложениям писем этих машин — вердикт, чтобы чтение брало их как
 * документы. Потом документы ТС дочитываются (`AutoScan`): пустые поля — оценочная, модель — встают сами. Без `--apply`
 * — только список.
 */
class DocScans extends Command
{
    protected $signature = 'media:doc-scans {--apply : перенести и дочитать, без него — только список}';

    protected $description = 'Сканы документов из фото — в документы, и дочитать их';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $vehicles = Vehicle::withoutGlobalScope(City::SCOPE)->whereIn('state', [VehicleState::Expected, VehicleState::Stored])->pluck('id');
        $offers = Offer::withoutGlobalScopes()->whereIn('state', [OfferState::Draft, OfferState::Open, OfferState::Sold, OfferState::Garage])->pluck('id');
        $touched = [];
        $moved = 0;

        $photos = Media::where('collection_name', 'photos')->where(fn ($q) => $q
            ->where(fn ($v) => $v->where('model_type', Vehicle::class)->whereIn('model_id', $vehicles))
            ->orWhere(fn ($o) => $o->where('model_type', Offer::class)->whereIn('model_id', $offers)));
        foreach ($photos->lazyById(200) as $media) {
            $path = $media->getCustomProperty('watermarked', false) ? Watermark::cleanPath($media) : $media->getPath();
            if (! is_file($path) || ! DocumentScan::is((string) file_get_contents($path))) {
                continue;
            }
            $moved++;
            $this->line(class_basename($media->model_type)." {$media->model_id}: {$media->file_name}");
            if (! $apply) {
                continue;
            }
            try {
                $model = $media->model;
                $model->addMedia($path)->preservingOriginal()->usingName($media->name)->usingFileName($media->file_name)
                    ->withCustomProperties(array_filter(['sha' => $media->getCustomProperty('sha'), 'source' => $media->getCustomProperty('source'), 'offer' => $media->getCustomProperty('offer')]))
                    ->toMediaCollection('papers');
                $media->delete();
                $media->model_type === Vehicle::class && $touched[$media->model_id] = true;
            } catch (Throwable $e) {
                $this->warn("Кадр {$media->id}: {$e->getMessage()}");
            }
        }

        // Вложения писем этих ТС: вердикт по содержимому — чтение возьмёт скан документом.
        $scans = 0;
        $attachments = Attachment::whereHas('message.thread', fn ($t) => $t->whereIn('vehicle_id', $vehicles))->whereNotNull('blob_sha');
        foreach ($attachments->with('message.thread')->lazyById(200) as $a) {
            if (! $a->isImage() || ! $a->isOnDisk() || DocumentScan::known($a->blob_sha) === false) {
                continue;
            }
            if (DocumentScan::known($a->blob_sha) || (($contents = $a->contents()) !== null && DocumentScan::is($contents))) {
                $scans++;
                $touched[$a->message->thread->vehicle_id] = true;
                $this->line("Письмо, ТС {$a->message->thread->vehicle_id}: {$a->filename}");
            }
        }

        if ($apply) {
            foreach (array_keys($touched) as $id) {
                ($v = Vehicle::withoutGlobalScope(City::SCOPE)->find($id)) && AutoScan::start($v);
            }
        }
        $this->info("Сканов среди кадров: {$moved}, во вложениях писем: {$scans}, ТС к дочитыванию: ".count($touched).($apply ? '' : ' (без --apply ничего не менялось)'));

        return self::SUCCESS;
    }
}
