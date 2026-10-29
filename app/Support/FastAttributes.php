<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Чтение поля модели без повторной работы (29.10.2026). Eloquent на каждое `$offer->state` заново склеивает весь список
 * кастов (`getCasts` — `array_merge` на каждый из нескольких вопросов про поле), ищет у поля мутаторы и разбирает дату
 * Carbon: 2–9 мкс на чтение против 0,1 у массива, а строка таблицы читает ~100 полей — «Без закупочной» в 667 строк
 * на треть состояла из этого.
 *
 * Здесь касты собираются раз на объект, что за поле (простое, каст, дата, с аксессором) — раз на класс и набор кастов, а значение
 * запоминается вместе с сырым значением, из которого получено: сырое то же (`===`) — то же и значение, сменилось любым
 * путём (запись, `refresh`, гидратация) — считается заново, без сброса по событиям. Запоминается только простое: скаляр,
 * массив, enum, дата (отдаётся копией — Carbon изменяемый). Поля с аксессором, классовым и шифрованным кастом — как в
 * Eloquent: их значение может зависеть не только от своего столбца. Загруженная связь (`$offer->deal`, `$car->brand`)
 * отдаётся сразу, если у ключа нет ни поля, ни каста, ни аксессора — ровно тогда Eloquent и сам вернул бы её, но через
 * перебор мутаторов и `isRelation`.
 */
trait FastAttributes
{
    /** @var array<string, string>|null */
    private ?array $fastCasts = null;

    /** @var array<string, array{0: mixed, 1: mixed}> поле => [сырое, значение] */
    private array $fastValues = [];

    /** Чьи виды полей: класс, а у объекта с кастами запроса (`withExists`, `withCasts`) — класс и эти касты. */
    private ?string $fastSet = null;

    /** @var array<string, array<string, string>> вид поля по набору кастов: raw, cast, date, eloquent */
    private static array $fastKindsBySet = [];

    /** @var array<string, array<string, bool>> ключ — просто связь (нет поля, каста, аксессора) по набору кастов */
    private static array $fastRelationsBySet = [];

    public function getAttribute($key)
    {
        return $this->fastAttribute($key);
    }

    /** Общее чтение: зовут `getAttribute` и `Cars\OnCar` (у дорожки свой `getAttribute` перед этим). */
    protected function fastAttribute($key)
    {
        if (is_string($key) && array_key_exists($key, $this->relations) && ! array_key_exists($key, $this->attributes)
            && (self::$fastRelationsBySet[$this->fastSet ?? static::class][$key] ??= ! $this->hasAttribute($key) && ! method_exists(Model::class, $key))) {
            return $this->relations[$key];
        }

        return parent::getAttribute($key);
    }

    public function getCasts()
    {
        return $this->fastCasts ??= parent::getCasts();
    }

    public function mergeCasts($casts)
    {
        $before = parent::getCasts();
        parent::mergeCasts($casts);
        // `newInstance` (каждая строка выборки) и `loadCount` сливают те же касты (у второго — с ключом `id`) — тогда всё
        // остаётся как у класса.
        if (($casts = parent::getCasts()) !== $before) {
            $this->fastCasts = null;
            $this->fastValues = [];
            $this->fastSet = static::class.'|'.md5(serialize($casts));
        }

        return $this;
    }

    public function getAttributeValue($key)
    {
        if (! array_key_exists($key, $this->attributes)) {
            return parent::getAttributeValue($key);
        }
        $raw = $this->attributes[$key];
        if (isset($this->fastValues[$key]) && $this->fastValues[$key][0] === $raw) {
            $value = $this->fastValues[$key][1];

            return $value instanceof CarbonInterface ? $value->copy() : $value;
        }
        $kind = $this->fastKind($key);
        $value = match ($kind) {
            'raw' => $raw,
            'cast' => $this->castAttribute($key, $raw),
            'date' => $raw === null ? null : $this->asDateTime($raw),
            default => parent::getAttributeValue($key),
        };
        if ($kind !== 'eloquent' && ($value === null || is_scalar($value) || is_array($value) || $value instanceof UnitEnum || $value instanceof CarbonInterface)) {
            $this->fastValues[$key] = [$raw, $value instanceof CarbonInterface ? $value->copy() : $value];
        }

        return $value;
    }

    /** Ветка `transformModelValue` для поля, без мутатора и классового каста: сам столбец, каст или дата. */
    private function fastKind(string $key): string
    {
        return self::$fastKindsBySet[$this->fastSet ?? static::class][$key] ??= $this->fastKindOf($key);
    }

    private function fastKindOf(string $key): string
    {
        return match (true) {
            $this->hasGetMutator($key), $this->hasAttributeGetMutator($key),
            // Enum — тоже «класс» для `isClassCastable`, но это простой каст: значение — от своего столбца.
            $this->isClassCastable($key) && ! $this->isEnumCastable($key), $this->isEncryptedCastable($key) => 'eloquent',
            $this->hasCast($key) => 'cast',
            in_array($key, $this->getDates(), false) => 'date',
            default => 'raw',
        };
    }
}
