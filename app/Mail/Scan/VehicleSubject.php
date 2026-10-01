<?php

namespace App\Mail\Scan;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Direction;
use App\Mail\Extraction\ScanFields;
use App\Park\Actions\FillFromDocs;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

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

    public function mail(): string
    {
        return '/mail';
    }

    public function topic(): string
    {
        return Topics::PARK;
    }

    public function fields(): array
    {
        return array_keys(ScanFields::LABELS);
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

    /**
     * Выбранное — в карточку тем же путём, что форма: правила `VehicleFields`, госномер слитно заглавными, цвет
     * словарём, VIN — не тот, что уже у другой ТС. Сменили марку без модели — модель прежней марки снимается.
     * Не прошедшее правила поле пропускается, остальное пишется.
     */
    public function apply(array $chosen, User $by): void
    {
        $data = [];
        $sources = [];
        foreach ($chosen as $field => $item) {
            $sources = [...$sources, ...($item['from'] ?? [])];
            $value = $item['value'];
            match ($field) {
                'brand' => ($id = Brand::known((string) $value)?->id) ? $data['brand_id'] = $id : null,
                'model' => null,
                'plate' => $data['plate'] = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $value)),
                'vin' => $data['vin'] = strtoupper((string) $value),
                'color' => $data['color'] = Colors::normalize((string) $value) ?? (string) $value,
                default => $data[$field] = $value,
            };
        }
        $brandId = $data['brand_id'] ?? $this->vehicle->brand_id;
        // Марки документа нет в справочнике — его модель не заводится под прежней маркой («Kia H5» из документа Hongqi).
        $unknown = isset($chosen['brand']) && ! Brand::known((string) $chosen['brand']['value']);
        if (! $unknown && isset($chosen['model']['value']) && ($brand = Brand::find($brandId))) {
            $data['model_id'] = CarModel::resolve($brand, (string) $chosen['model']['value'])->id;
        } elseif ($brandId !== $this->vehicle->brand_id) {
            $data['model_id'] = null;
        }
        if (isset($data['vin']) && app(FillFromDocs::class)->vinTaken($this->vehicle, $data['vin'])) {
            unset($data['vin']);
        }
        $errors = Validator::make($data, array_intersect_key(VehicleFields::rules(identity: false), $data))->errors();
        $data = array_diff_key($data, array_flip($errors->keys()));
        if ($data) {
            app(UpdateVehicle::class)($this->vehicle, $data, $by, $sources ?: ['документ']);
        }
    }

    public function refresh(): void
    {
        FillFromDocs::forget($this->vehicle);
        // Расхождения считаются сразу: метка «в документе иначе» встаёт в строке таблицы, не дожидаясь показа дела.
        app(FillFromDocs::class)->differences($this->vehicle->refresh());
        app(Publisher::class)->refresh(Topics::PARK, ['/cars/'.$this->vehicle->id]);
    }
}
