<?php

namespace App\Http\Admin;

use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Purchases\Car;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurchaseCarPhotoController
{
    public function store(Request $request, Car $car, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        try {
            $ingest->fromUpload($car, 'photos', $request->file('file'));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $car->update(['photos_count' => $car->photos()->count()]);

        return $this->gallery($car);
    }

    public function reorder(Request $request, Car $car)
    {
        Media::setNewOrder($request->validate(['order' => ['required', 'array']])['order']);

        return $this->gallery($car->refresh());
    }

    public function toggle(Car $car, Media $media)
    {
        $media->setCustomProperty('hidden', ! $media->getCustomProperty('hidden', false))->save();

        return $this->gallery($car->refresh());
    }

    public function main(Car $car, Media $media)
    {
        Media::setNewOrder($car->photos()->pluck('id')->reject(fn ($id) => $id === $media->id)->prepend($media->id)->all());
        $media->setCustomProperty('hidden', false)->save();

        return $this->gallery($car->refresh());
    }

    public function destroy(Car $car, Media $media)
    {
        abort_unless($media->model_id === $car->id && $media->model_type === $car::class, 404);
        $media->delete();
        $car->update(['photos_count' => $car->photos()->count()]);

        return $this->gallery($car->refresh());
    }

    private function gallery(Car $car)
    {
        $car->unsetRelation('media');

        return Stream::view('admin.purchases.gallery-stream', ['car' => $car]);
    }
}
