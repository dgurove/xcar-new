<?php

namespace App\Cars;

use App\Cars\Actions\JoinCars;
use App\Media\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Дорожка машины (`Car`): продажа, хранение, лот закупки. Своих полей машины у дорожки нет — `$offer->vin`,
 * `$vehicle->ref`, `$lot->dl` читают и пишут машину (`carMap`: имя у дорожки → колонка машины; у парковки номер убытка
 * исторически `ref`, у закупки — `dl`). Запись дорожки пишет и машину, одной транзакцией: новая машина или правка номера
 * и VIN, которые уже знают, встают на ту запись (`JoinCars::settle`). Фото и документы — у машины: `media` дорожки — это
 * её файлы (что видно этой дорожке — `carMediaScope`), новый файл ложится к машине. Запросы по полям машины — через
 * `whereCar`.
 *
 * @property int $car_id
 */
trait OnCar
{
    /** @var array<class-string, array<string, string>> */
    private static array $carMaps = [];

    /** Прежняя машина, что сольётся в найденную после записи (`JoinCars::settle`). */
    private ?int $carToJoin = null;

    /** Продажа и хранение встретились на найденной машине (`JoinCars::settle`). */
    private bool $carPaired = false;

    /**
     * Имя поля у дорожки → колонка машины. Дорожка может не отдавать машине поле со своим смыслом (у парковки `fuel` —
     * уровень топлива, а не вид) и называть поле по-своему.
     *
     * @return array<string, string>
     */
    public static function carMap(): array
    {
        return self::$carMaps[static::class] ??= (function () {
            $own = array_fill_keys(array_diff([...Car::FIELDS, ...Car::DERIVED], static::carOwnFields()), null);

            return array_combine(array_keys($own), array_keys($own)) + static::carAliases();
        })();
    }

    /** Свои поля дорожки с тем же именем, что у машины. @return list<string> */
    protected static function carOwnFields(): array
    {
        return [];
    }

    /** Свои имена полей машины. @return array<string, string> */
    public static function carAliases(): array
    {
        return [];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** Машина дорожки: загруженная, по `car_id`, у новой дорожки — новая. Загрузили без `car_id` — ошибка, а не пустая машина. */
    public function carRecord(): Car
    {
        if ($this->relationLoaded('car') && ($car = $this->getRelation('car'))) {
            return $car;
        }
        $id = $this->attributes['car_id'] ?? null;
        if ($id) {
            $car = Car::find($id) ?? throw new LogicException(static::class.' #'.$this->getKey().': нет машины '.$id);
            $this->setRelation('car', $car);

            return $car;
        }
        if ($this->exists) {
            throw new LogicException(static::class.' #'.$this->getKey().' загружена без car_id');
        }
        $car = new Car;
        $this->setRelation('car', $car);

        return $car;
    }

    public function getAttribute($key)
    {
        if (is_string($key) && ($column = static::carMap()[$key] ?? null)) {
            return $this->carRecord()->getAttribute($column);
        }
        // Марка и модель — связи машины: `$offer->brand?->name`.
        if ($key === 'brand' || $key === 'model') {
            return $this->carRecord()->getRelationValue($key);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (is_string($key) && ($column = static::carMap()[$key] ?? null)) {
            // Ключ номера считает машина сама.
            if (! in_array($column, Car::DERIVED, true)) {
                $this->carRecord()->setAttribute($column, $value);
            }

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Что правка меняет у машины — именами дорожки (`ref`, а не `claim_ref`): для истории и тех, кто ждёт поле.
     *
     * @return list<string>
     */
    public function carDirty(): array
    {
        if (! $this->relationLoaded('car') || ! $this->getRelation('car')) {
            return [];
        }
        $names = array_flip(static::carAliases());

        return array_values(array_map(fn ($c) => $names[$c] ?? $c, array_diff(array_keys($this->getRelation('car')->getDirty()), Car::DERIVED)));
    }

    /**
     * Поля машины — как свои и для «изменилось ли»: `isDirty('vin')` у предложения смотрит в машину. Запись (`getDirty`)
     * — только своё: колонок машины у дорожки нет.
     */
    public function isDirty($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();
        if (! $attributes) {
            return parent::isDirty() || $this->carDirty() !== [];
        }
        [$lane, $car] = $this->splitCarFields($attributes);

        return ($lane && parent::isDirty($lane)) || ($car && $this->relationLoaded('car') && (bool) $this->getRelation('car')?->isDirty($car));
    }

    public function wasChanged($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();
        if (! $attributes) {
            return parent::wasChanged() || ($this->relationLoaded('car') && (bool) $this->getRelation('car')?->wasChanged());
        }
        [$lane, $car] = $this->splitCarFields($attributes);

        return ($lane && parent::wasChanged($lane)) || ($car && $this->relationLoaded('car') && (bool) $this->getRelation('car')?->wasChanged($car));
    }

    public function getOriginal($key = null, $default = null)
    {
        if (is_string($key) && ($column = static::carMap()[$key] ?? null)) {
            return $this->carRecord()->getOriginal($column, $default);
        }

        return parent::getOriginal($key, $default);
    }

    /** @return array{0: list<string>, 1: list<string>} свои поля и колонки машины */
    private function splitCarFields(array $attributes): array
    {
        $map = static::carMap();
        $lane = $car = [];
        foreach ($attributes as $a) {
            isset($map[$a]) ? $car[] = $map[$a] : $lane[] = $a;
        }

        return [$lane, $car];
    }

    /**
     * Запись дорожки — и машины, одной транзакцией: машина первой (у дорожки — её `car_id`). Новая машина или новый номер и
     * VIN, что уже знают, — та же машина (`JoinCars::settle`). Работает и у `saveQuietly`: машина пишется не событием.
     */
    public function save(array $options = [])
    {
        try {
            return $this->saveWithCar($options);
        } catch (IdentityTaken $e) {
            // Ошибка поля — под именем поля этой дорожки (`ref` у парковки).
            throw $e->as(array_flip(static::carAliases())[$e->field] ?? $e->field);
        }
    }

    private function saveWithCar(array $options): bool
    {
        return DB::transaction(function () use ($options) {
            $car = $this->carRecord();
            if (! $car->exists || $car->isDirty(['claim_ref_key', 'vin'])) {
                $car = JoinCars::settle($this, $car);
            }
            if ($this->carIsDemo()) {
                $car->is_demo = true;
            }
            $carChanged = $car->exists && $car->isDirty();
            if (! $car->exists || $car->isDirty()) {
                $car->save();
            }
            $this->attributes['car_id'] = $car->id;
            $this->setRelation('car', $car);
            // Правили только машину — дорожка тоже «изменена»: время правки и её события (живые списки) — как у своей.
            if ($carChanged && $this->exists && $this->usesTimestamps() && ! parent::isDirty()) {
                $this->updateTimestamps();
            }
            $saved = parent::save($options);
            if ($saved && ($from = $this->carToJoin)) {
                $this->carToJoin = null;
                ($old = Car::find($from)) && app(JoinCars::class)($old, $car);
            }
            if ($saved && $this->carPaired) {
                JoinCars::paired($car);
            }
            $this->carPaired = false;
            // Жива ли машина (`Car::active`) — по её продажам и хранениям: новая дорожка, смена состояния или машины.
            if ($saved && ($this->wasRecentlyCreated || parent::wasChanged(['state', 'car_id']))) {
                $car->refreshActive();
            }

            return $saved;
        });
    }

    /** `JoinCars::settle`: дорожка ушла на найденную машину, прежнюю (была) слить в неё после записи. */
    public function joinCarAfterSave(?int $carId, bool $paired = false): void
    {
        $this->carToJoin = $carId;
        $this->carPaired = $paired;
    }

    /** Дорожка удалена, а у машины больше ничего нет — машина и её файлы уходят. */
    protected static function bootOnCar(): void
    {
        static::deleted(function ($lane) {
            $car = Car::find($lane->getAttributes()['car_id'] ?? null);
            if ($car && $car->orphan()) {
                $car->delete();
            } elseif ($car && array_key_exists('state', $lane->getAttributes())) {
                $car->refreshActive();
            }
        });
    }

    /** Демо-дорожка (у продажи — `is_demo`) — демо-машина: в сторожах не участвует. */
    protected function carIsDemo(): bool
    {
        return false;
    }

    /** Запрос по полям машины: `Offer::whereCar('vin', $vin)`, `->whereCar(fn ($c) => …)`. */
    public function scopeWhereCar(Builder $q, mixed ...$args): Builder
    {
        return $q->whereHas('car', fn ($c) => $c->where(...$args));
    }

    // ------------------------------------------------------------ файлы машины

    /**
     * Файлы машины, видные этой дорожке, — связью для `with('media')`. Ключ — `car_id` дорожки: одна выборка на пачку.
     *
     * @return HasMany<Media>
     */
    public function media(): HasMany
    {
        $relation = $this->hasMany(config('media-library.media_model'), 'model_id', 'car_id')->where('model_type', Car::class)->orderBy('order_column');
        $this->carMediaScope($relation->getQuery());

        return $relation;
    }

    /** Что из файлов машины видит дорожка: по умолчанию всё. */
    protected function carMediaScope(Builder $query): void {}

    /** @return Collection<int, Media> */
    public function getMedia(string $collection = 'default'): Collection
    {
        return $this->media->where('collection_name', $collection)->sortBy('order_column')->values();
    }

    public function getFirstMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->first();
    }

    /** @return Collection<int, Media> */
    public function photos(): Collection
    {
        return $this->getMedia('photos');
    }

    /** @return Collection<int, Media> */
    public function visiblePhotos(): Collection
    {
        return $this->photos()->reject(fn (Media $m) => $this->hides($m))->values();
    }

    /** Скрыт ли кадр здесь: у продажи и закупки — глаз (`Hidden`), у парковки скрытых нет. */
    protected function hides(Media $media): bool
    {
        return Hidden::is($media);
    }

    public function mainPhoto(): ?Media
    {
        return $this->visiblePhotos()->first();
    }

    /** @return Collection<int, Media> */
    public function papers(): Collection
    {
        return $this->getMedia('papers');
    }

    /** Такой файл у машины уже есть (`HasPhotos::hasFile`). */
    public function hasFile(string $sha): bool
    {
        return $this->carRecord()->hasFile($sha);
    }

    /** Метка нового файла этой дорожки (`park` у парковки: её файлы CRM не удаляет и выдача стирает письма). */
    public function carMediaMark(): array
    {
        return [];
    }

    /** Новый файл — к машине, с меткой дорожки: её не снимут и свои свойства вызова (`CarFileAdder`). */
    public function addMedia(string|UploadedFile $file): FileAdder
    {
        /** @var CarFileAdder $adder */
        $adder = app(CarFileAdder::class);

        return $adder->setSubject($this->carRecord())->setFile($file)->markedAs($this->carMediaMark());
    }

    public function addMediaFromRequest(string $key): FileAdder
    {
        return $this->addMedia(request()->file($key));
    }

    /** Текст файлом (PDF акта, вложение письма) — как у spatie, но с меткой дорожки. */
    public function addMediaFromString(string $text): FileAdder
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($tmp, $text);

        return $this->addMedia($tmp)->usingFileName('text.txt');
    }

    public function clearMediaCollection(string $collection = 'default'): void
    {
        $this->carRecord()->clearMediaCollection($collection);
    }
}
