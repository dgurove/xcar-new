<?php

namespace App\Cars;

use App\Cars\Events\CarChanged;
use App\Cars\Vin\Vin;
use App\Logistics\Transport;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Patterns;
use App\Media\HasPhotos;
use App\Offers\Offer;
use App\Park\Actions\FillFromDocs;
use App\Park\City;
use App\Park\Vehicle;
use App\Purchases\Car as PurchaseCar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

/**
 * Машина — одна запись на всё приложение (14.10.2026, владелец: «машина должна быть единой сущностью без дубликатов и
 * костылей — и в xcar, и в CRM, и в park, и в logistics»). Здесь всё, что про саму машину и её страховой случай:
 * номер убытка или ДЛ, VIN, госномер, марка, модель, год, характеристики, повреждения, бумаги, оценочная, страхователь,
 * фото и документы. Продажа (`Offers\Offer`), хранение (`Park\Vehicle`), лот закупки (`Purchases\Car`) и перевозка
 * (`Logistics\Transport`) — её дорожки: у каждой своё состояние, деньги и вендор, а машину они читают и пишут здесь
 * (`OnCar`). Продажа и хранение у машины по одной; номер убытка — одна машина навсегда, VIN — одна среди живых
 * (`active`: есть незакрытая продажа или хранение). Сторож — `Identity`, в базе — уникальные индексы.
 */
#[Fillable(self::FIELDS)]
class Car extends Model implements HasMedia
{
    use HasPhotos;

    /** Всё, что про машину; дорожки читают и пишут это через `OnCar`. */
    public const FIELDS = ['claim_ref', 'vin', 'plate', 'brand_id', 'model_id', 'year', 'color', 'mileage', 'body', 'transmission',
        'drive', 'fuel', 'engine_volume', 'engine_power', 'category', 'oversize', 'damage_cause', 'damage_zones', 'damage_note',
        'incident_date', 'is_runnable', 'has_keys', 'keys_count', 'papers', 'pts', 'sts', 'policy_no', 'value', 'insured_name', 'insured_phone',
        'flags', 'docs_required', 'holder'];

    /** Производные колонки: пишет сама машина. */
    public const DERIVED = ['claim_ref_key'];

    /** Марка и модель нужны почти всякому показу машины — грузятся с ней. */
    protected $with = ['brand', 'model'];

    protected $attributes = ['damage_zones' => '[]', 'flags' => '[]', 'docs_required' => '[]', 'oversize' => false, 'active' => false, 'is_demo' => false];

    protected function casts(): array
    {
        return [
            'year' => 'int', 'mileage' => 'int', 'keys_count' => 'int', 'engine_volume' => 'int', 'engine_power' => 'int', 'value' => 'int',
            'body' => Body::class, 'transmission' => Transmission::class, 'drive' => Drive::class, 'fuel' => Fuel::class,
            'damage_cause' => DamageCause::class, 'papers' => Papers::class, 'category' => Category::class,
            'damage_zones' => 'array', 'flags' => 'array', 'docs_required' => 'array',
            'oversize' => 'bool', 'is_runnable' => 'bool', 'has_keys' => 'bool', 'active' => 'bool', 'is_demo' => 'bool',
            'incident_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $car) {
            // Тип ТС — от кузова, иначе по марке и модели («Газель», «КАМАЗ»); вписанный руками не трогаем. У машины на
            // парковке тип — её ставка хранения: его ставит парковка, кузов из CRM его не меняет.
            $parked = fn () => $car->exists && Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $car->id)->exists();
            if (! $car->isDirty('category') && $car->isDirty('body') && $car->body?->category() && ! ($car->category && $parked())) {
                $car->category = $car->body->category();
            } elseif (! $car->category && ($car->brand_id || $car->body)) {
                $car->category = $car->body?->category() ?? Category::guess($car->title()) ?? Category::Passenger;
            }
            if ($car->isDirty(['brand_id', 'model_id'])) {
                $car->unsetRelation('brand')->unsetRelation('model');
            }
            Identity::guardCar($car);
        });
        // Поля, которые сверяет «в документе иначе» у ТС парковки, поменяли из любой двери (CRM, «✨», письмо) — метка
        // пересчитается при следующем показе дела.
        static::saved(function (self $car) {
            if ($car->wasChanged(['vin', 'plate', 'year', 'color', 'value', 'brand_id', 'model_id'])
                && ($vehicle = Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $car->id)->first())) {
                FillFromDocs::forget($vehicle);
            }
            // Экраны всех дорожек перечитываются (`Live\RefreshCar`) — после коммита: раньше записи перечитывать нечего,
            // откат — и правки не было. Новая машина — нет: у новой дорожки свои события.
            $fields = array_values(array_diff(array_keys($car->getChanges()), ['updated_at', 'created_at', 'active']));
            if ($fields && ! $car->wasRecentlyCreated) {
                DB::afterCommit(fn () => CarChanged::dispatch($car->id, $fields));
            }
        });
    }

    public function setClaimRefAttribute(?string $value): void
    {
        $value = trim((string) $value) ?: null;
        $value = Code::isNone($value) ? Code::NONE : $value;
        $this->attributes['claim_ref'] = $value;
        $this->attributes['claim_ref_key'] = Code::key($value);
    }

    public function setVinAttribute(?string $value): void
    {
        $this->attributes['vin'] = Vin::normalize($value);
    }

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = Patterns::plateKey($value);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'model_id');
    }

    // ------------------------------------------------------------ дорожки

    /** Продажа машины — одна на машину, в любом состоянии (архивная — тоже: её поднимают, а не заводят вторую). */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class)->withoutGlobalScopes();
    }

    /** Хранение на парковке — одно на машину; города управляющего машина не знает. */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->withoutGlobalScope(City::SCOPE);
    }

    /** Лоты закупок (Carcade выставляет тот же ДЛ в новой закупке — та же машина). */
    public function purchaseCars(): HasMany
    {
        return $this->hasMany(PurchaseCar::class);
    }

    public function transports(): HasMany
    {
        return $this->hasMany(Transport::class);
    }

    /**
     * Жива ли машина для «VIN — один среди живых»: есть продажа или хранение, и не всё закрыто. Демо — никогда. Зовут
     * дорожки после записи (`OnCar`); вернули из архива рядом с живым двойником — упрётся в индекс, как и раньше.
     */
    public function refreshActive(): void
    {
        $active = ! $this->is_demo && (
            Offer::withoutGlobalScopes()->where('car_id', $this->id)->where('is_demo', false)->whereNotIn('state', Identity::CLOSED_OFFER)->exists()
            || Vehicle::withoutGlobalScopes()->where('car_id', $this->id)->whereNotIn('state', Identity::CLOSED_VEHICLE)->exists());
        if ($active !== $this->active) {
            $this->active = $active;
            $this->save();
        }
    }

    /** Ни одной дорожки — машины больше нет нигде: запись и её файлы уходят. */
    public function orphan(): bool
    {
        return ! Offer::withoutGlobalScopes()->where('car_id', $this->id)->exists()
            && ! Vehicle::withoutGlobalScopes()->where('car_id', $this->id)->exists()
            && ! PurchaseCar::where('car_id', $this->id)->exists()
            && ! Transport::where('car_id', $this->id)->exists();
    }

    /**
     * Поле машины выражением над таблицей дорожки — для чипов-фильтров и сортировок списков (`Facet::column`,
     * `orderByRaw`): `Car::sql('offers', 'category')`.
     */
    public static function sql(string $lane, string $column): string
    {
        return "(select cars.{$column} from cars where cars.id = {$lane}.car_id)";
    }

    // ------------------------------------------------------------ подписи

    /**
     * Марка и модель — одна дверь для всех дорожек (16.10.2026: продажа, закупка, парковка и машина собирали имя каждая
     * сама). Своя запасная подпись — у дорожки: «ТС» у продажи и закупки, номер убытка или госномер у парковки. brand и
     * model — что подставить, когда марки или модели нет в справочнике (у лота закупки — как пришло в файле).
     */
    public function title(?string $brand = null, ?string $model = null): string
    {
        return trim(($this->brand?->name ?? $brand ?? '').' '.($this->model?->name ?? $model ?? ''));
    }

    /** Имя с годом: «Kia Rio, 2019»; без марки — пусто, запасное дорожка ставит сама. */
    public function titleWithYear(): string
    {
        $name = $this->title();

        return $name !== '' ? $this->withYear($name) : '';
    }

    /** Год через запятую к имени: «Kia Rio, 2019», «ТС, 2019». */
    public function withYear(string $name): string
    {
        return $name.($this->year ? ", {$this->year}" : '');
    }

    /** Подпись для «уже у …»: продажа номером, иначе ТС парковки, иначе лот закупки. */
    public function label(): string
    {
        return Identity::carName($this);
    }

    /** Знак над кадрами машины: запрет шеринга или идущая оценка у продажи, иначе запрет у лота закупки. */
    public function shareLocked(): bool
    {
        $offer = $this->relationLoaded('offer') ? $this->offer : $this->offer()->first(['id', 'car_id', 'share_locked', 'share_locked_by_estimate']);

        return $offer ? ($offer->share_locked || $offer->share_locked_by_estimate) : PurchaseCar::where('car_id', $this->id)->where('share_locked', true)->exists();
    }
}
