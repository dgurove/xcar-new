<?php

namespace App\Mail\Scan;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Direction;
use App\Mail\Extraction\ScanFields;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * «✨» у заведённой ТС: файлы входящих писем её веток; что выбрал человек — в карточку через `UpdateVehicle`
 * («Заполнено по документам» в истории дела). Марка и модель — по справочнику: марку заводить не будем.
 */
final class VehicleSubject implements Subject
{
    public function __construct(public readonly Vehicle $vehicle) {}

    public function key(): string
    {
        return 'v:'.$this->vehicle->id;
    }

    public function url(): string
    {
        return "/cars/{$this->vehicle->id}/scan";
    }

    public function title(): string
    {
        return $this->vehicle->titleWithYear();
    }

    public function hasCar(): bool
    {
        return (bool) $this->vehicle->brand_id;
    }

    public function vendor(): ?Vendor
    {
        return $this->vehicle->vendor;
    }

    public function ref(): ?string
    {
        return $this->vehicle->ref;
    }

    public function creates(): bool
    {
        return false;
    }

    public function files(): Collection
    {
        $this->vehicle->loadMissing('threads.messages.attachments');

        return Files::of($this->vehicle->threads->flatMap(fn ($t) => $t->messages)->filter(fn ($m) => $m->direction === Direction::In));
    }

    public function current(): array
    {
        return ScanFields::ofVehicle($this->vehicle->loadMissing(['brand', 'model']));
    }

    public function apply(array $chosen, User $by): void
    {
        $data = [];
        $sources = [];
        foreach ($chosen as $field => $item) {
            $sources = [...$sources, ...($item['from'] ?? [])];
            if ($field === 'brand') {
                $data['brand_id'] = Brand::known((string) $item['value'])?->id ?? $this->vehicle->brand_id;
            } elseif ($field !== 'model') {
                $data[$field] = $item['value'];
            }
        }
        if (isset($chosen['model']['value']) && ($brand = Brand::find($data['brand_id'] ?? $this->vehicle->brand_id))) {
            $data['model_id'] = CarModel::resolve($brand, (string) $chosen['model']['value'])->id;
        }
        if ($data) {
            app(UpdateVehicle::class)($this->vehicle, $data, $by, $sources ?: ['документ']);
        }
    }

    public function refresh(): void
    {
        app(Publisher::class)->refresh(Topics::PARK, ['/cars/'.$this->vehicle->id]);
    }
}
