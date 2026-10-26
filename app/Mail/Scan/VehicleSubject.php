<?php

namespace App\Mail\Scan;

use App\Cars\Actions\FillCar;
use App\Cars\Rejections;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Offers\Pricing;
use App\Park\Actions\FillFromDocs;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * «✨» у заведённой ТС: файлы всех писем её веток, наших пересылок тоже; что выбрал человек — в карточку через
 * `UpdateVehicle` («Заполнено по документам» в истории дела). Марка и модель — по справочнику: марку заводить не будем.
 */
final class VehicleSubject implements Subject
{
    public function __construct(public readonly Vehicle $vehicle) {}

    public function key(): string
    {
        return 'v:'.$this->vehicle->id;
    }

    public function rejectKey(): string
    {
        return Rejections::car($this->vehicle->car_id);
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

    /** Поля, что «✨» читает для ТС. */
    public function fields(): array
    {
        // Оценочная — всем вендорам (12.10.2026, владелец: стоимость из заявки Альфы СПб не вставала): хранение по ней
        // считает лестница прайса (`from_value`), а продаже нужна закупочная от неё (`Pricing::floorFrom`).
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

        // Все письма веток, и наши тоже: документы машины часто лежат в нашей пересылке или ответе вендору. И свои документы
        // ТС (12.10.2026): туда легли сканы из фото (`media:doc-scans`) и то, что кладут руками.
        $papers = collect(Paper::wrap($this->vehicle->papers()))->filter(fn (Paper $p) => DocumentText::scannable($p))->values();

        return Files::of($this->vehicle->threads->flatMap(fn ($t) => $t->messages), $papers);
    }

    public function current(): array
    {
        return ScanFields::ofVehicle($this->vehicle->loadMissing([]));
    }

    /**
     * Выбранное — в карточку тем же путём, что форма: правила `VehicleFields`, госномер слитно заглавными, цвет
     * словарём, VIN — не тот, что уже у другой ТС. Сменили марку без модели — модель прежней марки снимается.
     * Не прошедшее правила поле пропускается, остальное пишется. `$by` null — вписало фоновое чтение (`AutoScan`).
     */
    /** Выбранное — дверью `FillCar` (правила `VehicleFields`, VIN другой машины не пишется); без человека — только в пустое. */
    public function apply(array $chosen, ?User $by): void
    {
        $sources = array_values(array_unique(array_merge(...array_map(fn ($item) => $item['from'] ?? [], array_values($chosen)) ?: [[]])));
        app(FillCar::class)($this->vehicle, $chosen, $by, $by ? FillCar::CHOSEN : FillCar::EMPTY, ['sources' => $sources ?: ['документ']]);
    }

    public function refresh(): void
    {
        FillFromDocs::forget($this->vehicle);
        // Расхождения считаются сразу: метка «в документе иначе» встаёт в строке таблицы, не дожидаясь показа дела.
        app(FillFromDocs::class)->differences($this->vehicle->refresh());
        app(Publisher::class)->refresh(Topics::PARK, ['/cars/'.$this->vehicle->id]);
    }
}
