<?php

namespace App\Park\Actions;

use App\Cars\Colors;
use App\Cars\Names;
use App\Cars\Vin\VinDecoder;
use App\Cars\Vin\VinFact;
use App\Cars\Vin\VinText;
use App\Mail\Direction;
use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\ScanCar;
use App\Mail\Message;
use App\Mail\Scan\Files;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Пустые поля ТС — из её писем и документов (прогон 01.10.2026: у 58 из 70 машин без VIN он лежал в заявке, акте
 * или ЭПТС). Источники: разобранные входящие письма веток ТС (`Message::fields`, только своё — тема и тело) и
 * текст их документов (`DocumentText::layer`: прочитанное «✨» или текстовый слой, OCR здесь не запускается).
 * Пишется только в пустое поле и только одно значение: разные значения в разных источниках — «спорно», ТС не
 * трогается. VIN — если ему можно верить (`VinText`) и марка по нему сходится с маркой ТС. Заполненное поле,
 * которое документ называет иначе, тоже «спорно»: его решает человек.
 */
final class FillFromDocs
{
    private const FIELDS = ['vin', 'plate', 'year', 'color', 'value', 'model'];

    public function __construct(private UpdateVehicle $update) {}

    /** ТС на парковке и в пути с ветками писем. @return Collection<int, Vehicle> */
    public function vehicles(array $ids = []): Collection
    {
        return Vehicle::with(['brand', 'model', 'threads.messages.attachments'])
            ->whereIn('state', [VehicleState::Stored, VehicleState::Expected, VehicleState::InTransit])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->whereHas('threads')->orderBy('id')->get();
    }

    /**
     * Что вписать и что спорно. Спорное с `current` и `value` — карточка и документ расходятся, это видно в деле
     * (`differences`). @return array{fill: array<string, array{value: mixed, sources: list<string>}>,
     * disputed: list<array{field: string, text: string, current?: string, value?: string, sources?: list<string>}>}
     */
    public function plan(Vehicle $vehicle): array
    {
        $found = $this->found($vehicle);
        $fill = [];
        $disputed = [];
        foreach (self::FIELDS as $field) {
            $values = $found[$field] ?? [];
            if ($field === 'vin') {
                $values = $this->vins($vehicle, $values, $disputed);
            }
            if (! $values) {
                continue;
            }
            $current = $this->current($vehicle, $field);
            if ($current !== null && $current !== '') {
                $other = array_filter(array_keys($values), fn ($v) => ! $this->same($field, (string) $v, (string) $current));
                foreach ($other as $v) {
                    $disputed[] = ['field' => $field, 'current' => (string) $current, 'value' => (string) $v, 'sources' => $values[$v],
                        'text' => $this->label($field)." в карточке «{$current}», в документе «{$v}» (".implode(', ', $values[$v]).')'];
                }

                continue;
            }
            if (count($values) > 1) {
                $disputed[] = ['field' => $field, 'text' => $this->label($field).': '.implode(' / ', array_map(fn ($v) => "«{$v}» (".implode(', ', $values[$v]).')', array_keys($values)))];

                continue;
            }
            $value = array_key_first($values);
            $fill[$field] = ['value' => $value, 'sources' => $values[$value]];
        }

        return ['fill' => $fill, 'disputed' => $disputed];
    }

    /**
     * Где карточка и документы расходятся: поле → значение документа и откуда. На день в кеше — страница дела не
     * разбирает письма на каждом показе; правка карточки (`updated_at`) или новое письмо — пересчёт.
     *
     * @return array<string, array{value: string, sources: list<string>}>
     */
    public function differences(Vehicle $vehicle): array
    {
        $letters = $vehicle->threads()->withCount('messages')->get()->sum('messages_count');

        $version = Cache::get("park:docdiff:v:{$vehicle->id}", 0);

        return Cache::remember("park:docdiff:{$vehicle->id}:{$vehicle->updated_at?->timestamp}:{$letters}:{$version}", 86400, function () use ($vehicle) {
            $out = [];
            foreach ($this->plan($vehicle->loadMissing(['brand', 'model', 'threads.messages.attachments']))['disputed'] as $d) {
                if (isset($d['current'], $d['value']) && ! isset($out[$d['field']])) {
                    $out[$d['field']] = ['value' => $d['value'], 'sources' => $d['sources']];
                }
            }

            Cache::forever(self::flagKey($vehicle->id), (bool) $out);

            return $out;
        });
    }

    /**
     * Метка «в документе иначе» без разбора писем — для строк таблицы, плиток и окошка (`Park\Alerts`): ставит
     * `differences`, снимает `forget`. Пока дело с документами никто не открывал — метки нет.
     */
    public static function flagged(Vehicle $vehicle): bool
    {
        return (bool) Cache::memo()->get(self::flagKey($vehicle->id));
    }

    /** Метки многих ТС одним чтением кэша (ключ строки таблицы, `TableRows`). @return array<int, bool> */
    public static function flags(iterable $vehicles): array
    {
        $ids = collect($vehicles)->pluck('id');
        $got = $ids->isEmpty() ? [] : Cache::memo()->many($ids->map(fn ($id) => self::flagKey($id))->all());

        return $ids->mapWithKeys(fn ($id) => [$id => (bool) ($got[self::flagKey($id)] ?? false)])->all();
    }

    /** Документы ТС прочитаны заново («✨») или правили её поля: расхождения пересчитать при следующем показе дела. */
    public static function forget(Vehicle $vehicle): void
    {
        Cache::forever("park:docdiff:v:{$vehicle->id}", (int) Cache::get("park:docdiff:v:{$vehicle->id}", 0) + 1);
        Cache::forget(self::flagKey($vehicle->id));
    }

    private static function flagKey(int $id): string
    {
        return "park:docdiff:has:{$id}";
    }

    /** Взять значение документа вместо карточки: только то, что `differences` и показывает. */
    public function take(Vehicle $vehicle, string $field, string $value, User $by): bool
    {
        $diff = $this->differences($vehicle)[$field] ?? null;
        if (! $diff || $diff['value'] !== $value) {
            return false;
        }
        if ($field === 'model') {
            $model = $vehicle->brand?->models()->whereRaw('lower(name) = ?', [mb_strtolower($value)])->first();
            if (! $model) {
                return false;
            }
            ($this->update)($vehicle, ['model_id' => $model->id], $by, $diff['sources']);

            return true;
        }
        if ($field === 'vin' && $this->vinTaken($vehicle, $value)) {
            return false;
        }
        ($this->update)($vehicle, [$field => $value], $by, $diff['sources']);

        return true;
    }

    /** Вписать план. @return array<string, mixed> что вписано */
    public function __invoke(Vehicle $vehicle, ?array $plan = null): array
    {
        $plan ??= $this->plan($vehicle);
        $data = [];
        $sources = [];
        foreach ($plan['fill'] as $field => $item) {
            if ($field === 'model') {
                $model = $vehicle->brand?->models()->whereRaw('lower(name) = ?', [mb_strtolower((string) $item['value'])])->first();
                if (! $model) {
                    continue;
                }
                $data['model_id'] = $model->id;
            } else {
                $data[$field] = $item['value'];
            }
            array_push($sources, ...$item['sources']);
        }
        if ($data) {
            ($this->update)($vehicle, $data, null, $sources);
        }

        return $data;
    }

    /** Значения из писем и документов ТС: поле → значение → откуда. @return array<string, array<string, list<string>>> */
    private function found(Vehicle $vehicle): array
    {
        $found = [];
        $add = function (string $field, mixed $value, string $source) use (&$found) {
            $value = $this->clean($field, $value);
            if ($value !== null && ! in_array($source, $found[$field][$value] ?? [], true)) {
                $found[$field][$value][] = $source;
            }
        };
        $messages = $vehicle->threads->flatMap(fn ($t) => $t->messages)
            ->filter(fn (Message $m) => $m->direction === Direction::In && ! in_array($m->intent, [Intent::Billing->value, Intent::Auto->value], true));
        foreach ($messages as $message) {
            // Письмо о нескольких машинах («выдать ТС А и Б») — его VIN и номер про одну из них.
            $single = count(array_filter($message->keys(), fn ($k) => str_starts_with($k, 'code:'))) <= 1;
            foreach ($message->fields() as $field => $item) {
                if ($single && in_array($field, ['vin', 'plate', 'year', 'color'], true) && in_array($item['source'] ?? '', ['subject', 'body', 'text'], true)) {
                    $add($field, $item['value'], 'письмо');
                }
            }
        }
        // Файлы — те же, что видит «✨» в деле (`Scan\Files`), подписи — те же, что уйдут в историю дела.
        foreach (Files::of($messages) as $attachment) {
            $text = $attachment->isImage() ? DocumentText::cached($attachment) : DocumentText::layer($attachment);
            if (! $text) {
                continue;
            }
            $source = Files::label($attachment);
            $doc = DocumentFields::extract($text);
            if ($this->otherCar($vehicle, $doc)) {
                continue;
            }
            foreach (['vin', 'plate', 'year', 'color', 'value'] as $field) {
                if (isset($doc[$field])) {
                    $add($field, $doc[$field]['value'], $source);
                }
            }
            // Модель — только из документа той же марки.
            if (isset($doc['brand'], $doc['model']) && $vehicle->brand && Names::brand((string) $doc['brand']['value'])?->id === $vehicle->brand_id) {
                $add('model', $doc['model']['value'], $source);
            }
        }

        return $found;
    }

    /** VIN, которым можно верить и чья марка сходится с ТС; прочтения одного VIN по-разному — в одно. */
    private function vins(Vehicle $vehicle, array $values, array &$disputed): array
    {
        $ok = [];
        foreach ($values as $vin => $sources) {
            $vin = (string) $vin;
            if (! VinText::plausible($vin)) {
                continue;
            }
            if ($twin = $this->vinTaken($vehicle, $vin)) {
                $disputed[] = ['field' => 'vin', 'text' => "VIN «{$vin}» (".implode(', ', $sources).") уже у ТС {$twin->id} {$twin->titleWithYear()}"];

                continue;
            }
            if ($vehicle->brand && ! $this->agrees($vehicle, $vin)) {
                $disputed[] = ['field' => 'vin', 'text' => "VIN «{$vin}» (".implode(', ', $sources).") — по нему не {$vehicle->brand->name}"];

                continue;
            }
            foreach (array_keys($ok) as $have) {
                if ($best = VinText::better($have, $vin)) {
                    $merged = array_values(array_unique([...$ok[$have], ...$sources]));
                    unset($ok[$have]);
                    $ok[$best] = $merged;

                    continue 2;
                }
            }
            $ok[$vin] = $sources;
        }

        return $ok;
    }

    /**
     * Марка по VIN — та же, что у ТС: память базы или декодер называют её марку; у нас уже стоят машины этой марки с
     * тем же WMI (Belgee у Geely, Skoda в Калуге у Volkswagen, Kia у Автотора — это знает база, а не список
     * заводов в коде); или производитель WMI в справочнике называет марку.
     */
    private function agrees(Vehicle $vehicle, string $vin): bool
    {
        if (($car = ScanCar::carOfVin($vin)) && $car['brand']->id === $vehicle->brand_id) {
            return true;
        }
        $wmi = strtoupper(substr($vin, 0, 3));
        if (VinFact::where('brand_id', $vehicle->brand_id)->where('prefix', 'like', $wmi.'%')->exists()) {
            return true;
        }
        $result = app(VinDecoder::class)->decode($vin);
        $maker = mb_strtolower(trim(((string) $result->get('brand')).' '.((string) $result->get('manufacturer'))));
        $brand = $vehicle->brand;
        foreach (array_filter([mb_strtolower((string) preg_replace('/\s*\(.*\)/u', '', $brand->name)), $brand->slug, mb_strtolower((string) $brand->name_ru)]) as $token) {
            if (mb_strlen($token) >= 3 && str_contains($maker, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Документ о другой машине (в ветке ТС пересланное письмо о соседней, общий акт): его VIN не тот — дальше двух
     * знаков от VIN карточки (ближе — ошибка OCR той же машины), или марка в нём другая. Такой документ не даёт ни
     * года, ни цвета, ни стоимости.
     */
    private function otherCar(Vehicle $vehicle, array $doc): bool
    {
        $vin = (string) ($doc['vin']['value'] ?? '');
        if ($vin !== '' && $vehicle->vin && strlen($vehicle->vin) === 17 && levenshtein(strtoupper($vehicle->vin), strtoupper($vin)) > 2) {
            return true;
        }
        $brand = isset($doc['brand']) ? Names::brand((string) $doc['brand']['value']) : null;

        return $brand && $vehicle->brand_id && $brand->id !== $vehicle->brand_id;
    }

    /** VIN уже стоит у другой ТС: второй раз его не пишем — письма и предложения ищут машину по VIN. */
    public function vinTaken(Vehicle $vehicle, string $vin): ?Vehicle
    {
        return Vehicle::where('vin', strtoupper($vin))->where('id', '!=', $vehicle->id)->whereNull('cancelled_at')->first();
    }

    private function clean(string $field, mixed $value): ?string
    {
        $value = trim((string) $value);

        return match ($field) {
            'vin' => strlen($v = strtoupper($value)) === 17 ? $v : null,
            'plate' => ($v = mb_strtoupper((string) preg_replace('/\s+/u', '', $value))) !== '' ? $v : null,
            'year' => ((int) $value >= 1980 && (int) $value <= (int) date('Y') + 1) ? (string) (int) $value : null,
            'color' => Colors::normalize($value),
            'value' => (int) $value >= 10_000 ? (string) (int) $value : null,
            'model' => $value !== '' ? $value : null,
        };
    }

    private function current(Vehicle $vehicle, string $field): mixed
    {
        return $field === 'model' ? $vehicle->model?->name : $vehicle->{$field};
    }

    private function same(string $field, string $a, string $b): bool
    {
        return match ($field) {
            'color' => Colors::normalize($a) === Colors::normalize($b) || mb_strtolower($a) === mb_strtolower($b),
            'model' => mb_strtolower((string) preg_replace('/[\s\-]+/u', '', $a)) === mb_strtolower((string) preg_replace('/[\s\-]+/u', '', $b)),
            default => mb_strtoupper((string) preg_replace('/\s+/u', '', $a)) === mb_strtoupper((string) preg_replace('/\s+/u', '', $b)),
        };
    }

    private function label(string $field): string
    {
        return ['vin' => 'VIN', 'plate' => 'Госномер', 'year' => 'Год', 'color' => 'Цвет', 'value' => 'Стоимость', 'model' => 'Модель'][$field];
    }
}
