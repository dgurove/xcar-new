<?php

namespace App\Cars\Sources;

use App\Cars\Car;
use App\Mail\Direction;
use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\ScanFields;
use App\Mail\Message;
use App\Mail\Scan\Files;
use App\Mail\Scan\OfferSubject;
use App\Mail\Scan\ScanFile;
use App\Mail\Scan\VehicleSubject;
use App\Mail\Thread;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Support\Ago;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Что о машине говорят её источники — заново целиком, строками `car_values` (07.11.2026): лот Мигторга её продажи,
 * входящие письма её веток (тема и тело, только письма об одной машине), каждый прочитанный документ — вложения писем и
 * документы машины (текст из кеша чтения, OCR здесь не запускается: читает `ScanAttachments`). Документ о другой
 * машине (`ScanFields::otherCar`) не даёт ничего. Потом пустые поля, где источники согласны, вписываются сами
 * (`fill`, владелец: «сразу в пустое»), спорные ждут выбора (`Variants`), число спорных — `cars.value_conflicts`.
 *
 * Зовут: конец чтения документов (`AutoScan::finished`), лот Мигторга (`ImportMigtorgLot`), письмо по машине
 * (`FillFromLetter`), «Это документ» у фото, бэкфилл `cars:collect-values`.
 */
final class Collect
{
    public function __invoke(Car $car, bool $fill = true): void
    {
        try {
            $rows = [...$this->migtorg($car), ...$this->letters($car), ...$this->documents($car)];
        } catch (Throwable $e) {
            Log::warning('Источники машины не собрались', ['car' => $car->id, 'error' => $e->getMessage()]);

            return;
        }
        DB::transaction(function () use ($car, $rows) {
            DB::table('car_values')->where('car_id', $car->id)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('car_values')->insert($chunk);
            }
        });
        if ($fill) {
            app(Pick::class)->fillEmpty($car);
        }
        Variants::recount($car->refresh());
    }

    /** @return list<array<string, mixed>> */
    private function migtorg(Car $car): array
    {
        $offer = $car->offer;
        if (! $offer) {
            return [];
        }
        $lot = DB::table('migtorg_lots')->where('offer_id', $offer->id)->whereNotNull('data')->orderByDesc('id')->first();
        if (! $lot) {
            return [];
        }

        return $this->rows($car, 'migtorg', (string) $lot->id, 'Мигторг, лот '.$lot->id, Values::of(ImportMigtorgLot::fieldsOf($lot), $car, trusted: true));
    }

    /** @return list<array<string, mixed>> */
    private function letters(Car $car): array
    {
        $out = [];
        foreach ($this->messages($car)->filter(fn (Message $m) => $m->direction === Direction::In) as $message) {
            // Письмо о нескольких машинах («выдать ТС А и Б») — его VIN и номер про одну из них.
            if (count(array_filter($message->keys(), fn ($k) => str_starts_with($k, 'code:'))) > 1) {
                continue;
            }
            $fields = array_filter($message->fields(), fn ($item, $field) => in_array($field, ['vin', 'plate', 'year', 'color', 'brand', 'model'], true)
                && in_array($item['source'] ?? '', ['subject', 'body', 'text'], true), ARRAY_FILTER_USE_BOTH);
            if (ScanFields::otherCar($car->vin, $car->brand_id, $fields['vin']['value'] ?? null, $fields['brand']['value'] ?? null)) {
                continue;
            }
            $title = 'Письмо '.Ago::at($message->date_at ?? $message->created_at).($message->from_name ? ', '.$message->from_name : '');
            $out = [...$out, ...$this->rows($car, 'letter', (string) $message->id, $title, Values::of($fields, $car, newModels: false))];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function documents(Car $car): array
    {
        $out = [];
        foreach (self::files($car) as $file) {
            // Скан — то, что прочитано; PDF и docx — и текстовый слой без OCR (ложится в кеш).
            if ($file->isPhoto() || ! ($text = $file->isImage() ? DocumentText::cached($file) : DocumentText::layer($file))) {
                continue;
            }
            $fields = DocumentFields::extract($text);
            if (ScanFields::otherCar($car->vin, $car->brand_id, $fields['vin']['value'] ?? null, $fields['brand']['value'] ?? null)) {
                continue;
            }
            $out = [...$out, ...$this->rows($car, 'doc', $file->scanId(), Files::label($file), Values::of($fields, $car))];
        }

        return $out;
    }

    /**
     * Файлы машины, которые читаются как документы: вложения писем её продажи и хранения (наших пересылок тоже) и
     * документы машины. @return Collection<int, ScanFile>
     */
    public static function files(Car $car): Collection
    {
        $files = collect();
        if ($car->offer) {
            $files = $files->concat((new OfferSubject($car->offer))->files());
        }
        if ($car->vehicle) {
            $files = $files->concat((new VehicleSubject($car->vehicle))->files());
        }

        return $files->unique(fn (ScanFile $f) => $f->scanId())->values();
    }

    /** @return Collection<int, Message> */
    private function messages(Car $car): Collection
    {
        $threads = Thread::query()->where(fn ($q) => $q->when($car->offer, fn ($q, $o) => $q->orWhere('offer_id', $o->id))
            ->when($car->vehicle, fn ($q, $v) => $q->orWhere('vehicle_id', $v->id)));
        if (! $car->offer && ! $car->vehicle) {
            return collect();
        }

        return Message::whereIn('thread_id', $threads->select('id'))->get()
            ->reject(fn (Message $m) => $m->intent === Intent::Billing->value || Intent::silent($m->intent));
    }

    /** @return list<array<string, mixed>> */
    private function rows(Car $car, string $source, string $id, string $title, array $values): array
    {
        $out = [];
        foreach ($values as $field => $v) {
            if ($v['label'] === '') {
                continue;
            }
            $out[] = ['car_id' => $car->id, 'field' => $field, 'source' => $source, 'source_id' => $id, 'title' => mb_strimwidth($title, 0, 120),
                'write' => json_encode($v['write']), 'key' => mb_substr($v['key'], 0, 160), 'label' => mb_strimwidth($v['label'], 0, 200), 'created_at' => now()];
        }

        return $out;
    }
}
