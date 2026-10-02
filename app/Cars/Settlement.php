<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'region_code', 'is_federal_city'])]
class Settlement extends Model
{
    /** @var array<string, ?array{id: int, name: string}> имя → город справочника (`named`) */
    private static array $named = [];

    public function title(): string
    {
        return $this->name;
    }

    /**
     * Город справочника по имени: без регистра, «ё» как «е»; не город — null. Одно место на разбор писем (город темы
     * перебирают по словам, по запросу на слово) и «Завести»; ответ помнится в процессе — справочник городов не меняется.
     *
     * @return array{id: int, name: string}|null
     */
    public static function named(string $name): ?array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .,;:');
        if ($name === '' || mb_strlen($name) > 40) {
            return null;
        }
        $key = str_replace('ё', 'е', mb_strtolower($name));
        if (! array_key_exists($key, self::$named)) {
            $city = self::whereRaw("replace(lower(name), 'ё', 'е') = ?", [$key])->first(['id', 'name']);
            self::$named[$key] = $city ? ['id' => $city->id, 'name' => $city->name] : null;
        }

        return self::$named[$key];
    }
}
