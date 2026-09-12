<?php

namespace App\Http\Admin;

use App\Live\Stream;
use App\Media\Actions\RotatePhoto;
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
            $ingest->fromPhone($car, 'photos', $request);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $car->update(['photos_count' => $car->photos()->count()]);

        return $this->gallery($car);
    }

    public function reorder(Request $request, Car $car)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($order, $car->photos()->pluck('id')->all())));

        return $this->gallery($car->refresh());
    }

    public function toggle(Car $car, Media $media)
    {
        $this->own($car, $media);
        $media->setCustomProperty('hidden', ! $media->getCustomProperty('hidden', false))->save();

        return $this->gallery($car->refresh());
    }

    public function rotate(Car $car, Media $media, RotatePhoto $rotate)
    {
        $this->own($car, $media);
        $rotate($media);

        return $this->gallery($car->refresh());
    }

    public function destroy(Car $car, Media $media)
    {
        $this->own($car, $media);
        $media->delete();
        $car->update(['photos_count' => $car->photos()->count()]);

        return $this->gallery($car->refresh());
    }

    private function own(Car $car, Media $media): void
    {
        abort_unless($media->model_id === $car->id && $media->model_type === $car::class, 404);
    }

    private function gallery(Car $car)
    {
        $car->unsetRelation('media');

        return Stream::view('admin.purchases.gallery-stream', ['car' => $car]);
    }
}
