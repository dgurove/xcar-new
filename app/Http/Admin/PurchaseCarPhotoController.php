<?php

namespace App\Http\Admin;

use App\Live\Stream;
use App\Media\Actions\RotatePhoto;
use App\Media\Hidden;
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
        Hidden::toggle($media);

        return $this->gallery($car->refresh());
    }

    public function rotate(Request $request, Car $car, Media $media, RotatePhoto $rotate)
    {
        $this->own($car, $media);
        $rotate($media, RotatePhoto::turns($request));

        return $this->gallery($car->refresh());
    }

    public function destroy(Car $car, Media $media)
    {
        $this->own($car, $media);
        $media->delete();

        return $this->gallery($car->refresh());
    }

    private function own(Car $car, Media $media): void
    {
        // Файл машины лота (`Cars\Car`).
        abort_unless($media->model_type === \App\Cars\Car::class && $media->model_id === $car->car_id, 404);
    }

    private function gallery(Car $car)
    {
        $car->unsetRelation('media');

        return Stream::view('admin.purchases.gallery-stream', ['car' => $car]);
    }
}
