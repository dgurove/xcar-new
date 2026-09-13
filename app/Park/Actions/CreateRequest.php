<?php

namespace App\Park\Actions;

use App\Support\Nav;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Vin\RememberVin;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Заявка руками или из письма. На приём машина заводится тут же, на остальное — уже существующая. */
final class CreateRequest
{
    public function __invoke(User $by, RequestType $type, ?Vehicle $vehicle, array $data): Request
    {
        Nav::forgetStaffCounts();
        return DB::transaction(function () use ($by, $type, $vehicle, $data) {
            if (! $vehicle) {
                $brand = ! empty($data['brand']) ? Brand::resolve($data['brand']) : (! empty($data['brand_id']) ? Brand::find($data['brand_id']) : null);
                $model = $brand && ! empty($data['model']) ? CarModel::resolve($brand, $data['model']) : (! empty($data['model_id']) ? CarModel::find($data['model_id']) : null);
                $vehicle = Vehicle::create([
                    'ref' => $data['ref'] ?? null, 'vin' => $data['vin'] ?? null, 'plate' => $data['plate'] ?? null, 'year' => $data['year'] ?? null,
                    'color' => $data['color'] ?? null, 'brand_id' => $brand?->id, 'model_id' => $model?->id, 'client_id' => $data['client_id'] ?? null,
                    'state' => VehicleState::Expected,
                ]);
                $vehicle->log(EventType::Created, $by);
                (new RememberVin)($vehicle);
            }

            return Request::create([
                'vehicle_id' => $vehicle->id,
                'type' => $type,
                'thread_id' => $data['thread_id'] ?? null,
                'yard_id' => $data['yard_id'] ?? null,
                'planned_at' => $data['planned_at'] ?? null,
                'contact' => $data['contact'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);
        });
    }
}
