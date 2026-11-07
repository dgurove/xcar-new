<?php

namespace App\Cars\Jobs;

use App\Cars\Car;
use App\Mail\Scan\AutoScan;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * У машины появился документ (приложили руками, доехал из письма, сканы из фото, «Это документ») — прочитать её документы
 * в фоне (07.11.2026, `AutoScan::readDocs`): у любой живой машины, а не только у заведённой почтой. Пачка файлов — одна
 * задача: постановка на машину уникальна, с задержкой, пока ложатся остальные. Черновик у поставщика (partner.xcar.ru,
 * `Offer::heldByPartner`) не читается: поля сами не появляются у него на глазах; отдал цену — читается (`Partner\Handover`).
 */
final class ReadCarDocs implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $carId)
    {
        $this->onConnection('database')->onQueue('default')->delay(now()->addSeconds(20))->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->carId;
    }

    public function handle(): void
    {
        $car = Car::find($this->carId);
        if ($car?->offer?->heldByPartner()) {
            return;
        }
        foreach (array_filter([$car?->offer, $car?->vehicle]) as $lane) {
            AutoScan::readDocs($lane);
        }
    }
}
