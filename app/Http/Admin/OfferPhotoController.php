<?php

namespace App\Http\Admin;

use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Offers\Offer;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/** Фото и документы оффера. Загрузка по одному файлу за запрос — так работает прогресс на телефоне. */
class OfferPhotoController
{
    public function store(Request $request, Offer $offer, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');

        try {
            if ($request->input('collection') === 'papers') {
                $offer->addMedia($file)->usingFileName(self::safeName($file->getClientOriginalName()))->toMediaCollection('papers');
            } else {
                $ingest->fromPhone($offer, 'photos', $request);
            }
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($offer);
    }

    public function reorder(Request $request, Offer $offer)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($order, $offer->photos()->pluck('id')->all())));

        return $this->gallery($offer->refresh());
    }

    public function toggle(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        $media->setCustomProperty('hidden', ! $media->getCustomProperty('hidden', false))->save();

        return $this->gallery($offer->refresh());
    }

    public function rotate(Offer $offer, Media $media, RotatePhoto $rotate)
    {
        $this->own($offer, $media);
        $rotate($media);

        return $this->gallery($offer->refresh());
    }

    public function destroy(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        $media->delete();

        return $this->gallery($offer->refresh());
    }

    private function own(Offer $offer, Media $media): void
    {
        abort_unless($media->model_id === $offer->id && $media->model_type === $offer::class, 404);
    }

    private function gallery(Offer $offer)
    {
        $offer->unsetRelation('media');

        return response()
            ->view('admin.offers.gallery-stream', ['offer' => $offer])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    private static function safeName(string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', pathinfo($name, PATHINFO_FILENAME)), '-.') ?: 'dokument';

        return $base.($ext ? '.'.strtolower($ext) : '');
    }
}
