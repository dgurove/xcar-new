<?php

namespace App\Http\Cars;

use App\Cars\Sources\Collect;
use App\Cars\Sources\Pick;
use App\Cars\Sources\Values;
use App\Mail\Scan\AutoScan;
use App\Media\DocumentScan;
use App\Media\Watermark;
use App\Offers\Offer;
use App\Park\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Спорное поле машины (07.11.2026, `Cars\Sources`): выбрать вариант или оставить как есть — CRM у предложения
 * (`/offers/{n}/fields/{field}`, дверь `offer.edit`) и парковка у ТС (`/cars/{v}/fields/{field}`, `park.manage`). Ответ —
 * JSON (карточка в форме с «Сохранить», вложенной формы нет): `ok`, `stale` — поменяли, пока смотрел, и тост. И «Это
 * документ» у фото в просмотре: кадр — в документы машины, его прочтут сами.
 */
final class CarFieldController
{
    public function offer(Request $request, Offer $offer, string $field)
    {
        return $this->pick($request, $offer, $field);
    }

    public function vehicle(Request $request, Vehicle $vehicle, string $field)
    {
        return $this->pick($request, $vehicle, $field);
    }

    public function offerDocument(Request $request, Offer $offer)
    {
        return $this->document($request, $offer);
    }

    public function vehicleDocument(Request $request, Vehicle $vehicle)
    {
        return $this->document($request, $vehicle);
    }

    private function pick(Request $request, Offer|Vehicle $lane, string $field)
    {
        abort_unless(isset(Values::FIELDS[$field]), 404);
        $data = $request->validate(['key' => ['nullable', 'string', 'max:160'], 'seen' => ['nullable', 'string', 'max:160'], 'keep' => ['nullable', 'boolean']]);
        $pick = app(Pick::class);
        [$state, $toast] = $request->boolean('keep')
            ? $pick->keep($lane, $field, $data['seen'] ?? null, $request->user())
            : $pick->choose($lane, $field, (string) ($data['key'] ?? ''), $data['seen'] ?? null, $request->user());

        return response()->json(['ok' => $state === Pick::DONE, 'stale' => $state === Pick::STALE, 'toast' => $toast]);
    }

    /**
     * Фото, на котором документ (ПТС снят на столе — белого мало, `DocumentScan` его не узнал): кадр машины — в документы,
     * вложение письма — вердиктом «скан» по содержимому; дальше его читают как документ (`AutoScan::readDocs`).
     */
    private function document(Request $request, Offer|Vehicle $lane)
    {
        $id = (string) $request->validate(['file' => ['required', 'string', 'max:40']])['file'];
        $car = $lane->carRecord();
        if (str_starts_with($id, 'm')) {
            $media = Media::where('model_type', $car::class)->where('model_id', $car->id)->where('collection_name', 'photos')->findOrFail((int) ltrim($id, 'm'));
            DocumentScan::confirm($media->getCustomProperty('sha'));
            $path = $media->getCustomProperty('watermarked', false) ? Watermark::cleanPath($media) : $media->getPath();
            $car->addMedia($path)->preservingOriginal()->usingName($media->name)->usingFileName($media->file_name)
                ->withCustomProperties(array_filter(['sha' => $media->getCustomProperty('sha'), 'source' => $media->getCustomProperty('source'), 'park' => $media->getCustomProperty('park')]))
                ->toMediaCollection('papers');
            $media->delete();
        } else {
            $sha = DB::table('mail_attachments')->where('id', (int) $id)->whereIn('id', Collect::files($car)->map->scanId()->filter(fn ($i) => ctype_digit($i))->all())->value('blob_sha');
            abort_unless($sha, 404);
            DocumentScan::confirm($sha);
        }
        AutoScan::readDocs($lane);

        return response()->json(['ok' => true, 'toast' => 'Читаем как документ']);
    }
}
