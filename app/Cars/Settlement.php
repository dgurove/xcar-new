<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Населённый пункт из ОКТМО (02.10.2026, `SettlementImport`): город, посёлок, село, деревня — с регионом (`region_code`
 * — номер, как на номерах машин) и районом. `rank` — порядок в подсказке: город 1, центр района своего вида выше
 * остальных, посёлки городского типа до 4, сёла до 6, деревни до 8.
 */
#[Fillable(['name', 'type', 'region_code', 'region_id', 'district', 'oktmo', 'rank', 'is_federal_city'])]
class Settlement extends Model
{
    /** Город — то, что ищется по одному слову без региона и типа (тема письма: «Победа» и «Восход» — посёлки, не города). */
    public const TOWN = 2;

    /** Как место пишут в адресе перед именем: «г.», «пгт», «д.», «пос.», «ст-ца». */
    private const TYPED = '/(?:^|[\s,(])(?:г|гор|город|пгт|рп|п|пос|посёлок|поселок|д|дер|деревня|с|село|ст-ца|станица|х|хутор|аул|сл|слобода|мкр)(?:\.\s*|\s+)([А-ЯЁ][а-яё\-]+(?:\s[А-ЯЁ][а-яё\-]+){0,2})/u';

    /** @var array<string, ?array{id: int, name: string, title: string}> запрос → место справочника (`named`) */
    private static array $named = [];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** «Серпухов, 50» — имя и номер региона: одно имя бывает в двух сотнях регионов; место-регион (`рег`) — без номера. */
    public function title(): string
    {
        return $this->region_code && $this->type !== 'рег' ? $this->name.', '.$this->region_code : $this->name;
    }

    /** Район коротко: «Тосненский р-н», «Пермский м. о.», «г. о. Химки»; район — сам город — пусто. */
    public function districtShort(): ?string
    {
        if (! $this->district || str_contains($this->district, $this->name)) {
            return null;
        }

        return strtr($this->district, ['муниципальный район' => 'р-н', 'Муниципальный район' => 'р-н', 'муниципальный округ' => 'м. о.', 'Муниципальный округ' => 'м. о.', 'городской округ' => 'г. о.', 'Городской округ' => 'г. о.']);
    }

    /**
     * Место справочника по имени: без регистра, «ё» как «е». Без региона и без `villages` — только города (слово
     * темы письма «Победа» или «Восход» — не посёлок); с регионом — любое место в нём. Из
     * одноимённых — город, потом центр района. Ответ помнится в процессе — справочник не меняется.
     *
     * @return array{id: int, name: string, title: string}|null
     */
    public static function named(string $name, ?Region $region = null, bool $villages = false): ?array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .,;:');
        if ($name === '' || mb_strlen($name) > 60) {
            return null;
        }
        $key = str_replace('ё', 'е', mb_strtolower($name));
        $memo = $key.'|'.($region?->id ?? '').'|'.(int) $villages;
        if (! array_key_exists($memo, self::$named)) {
            $place = self::whereRaw("replace(lower(name), 'ё', 'е') = ?", [$key])
                ->when($region, fn ($q) => $q->where('region_id', $region->id))
                ->when(! $region && ! $villages, fn ($q) => $q->where('rank', '<=', self::TOWN))
                ->orderBy('rank')->orderBy('id')->first(['id', 'name', 'region_code']);
            self::$named[$memo] = $place ? ['id' => $place->id, 'name' => $place->name, 'title' => $place->title()] : null;
        }

        return self::$named[$memo];
    }

    /**
     * Место из адреса: «Пермский край, д. Ванюки, ул. …», «Ярославская обл, р-н Ярославский, д Бегоулево», «г. Москва,
     * ул. …». Регион из адреса сужает поиск, место с типом («д.», «пгт») ищется и среди деревень; без типа — части
     * адреса через запятую, города.
     *
     * @return array{id: int, name: string, title: string}|null
     */
    public static function inAddress(?string $address): ?array
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }
        $region = Region::inText($address);
        preg_match_all(self::TYPED, $address, $m);
        foreach ($m[1] as $name) {
            // «п Большой Исток Сысертского района» — сначала все слова, потом короче.
            $words = explode(' ', $name);
            for ($n = count($words); $n >= 1; $n--) {
                if ($place = self::named(implode(' ', array_slice($words, 0, $n)), $region, villages: true)) {
                    return $place;
                }
            }
        }
        foreach (preg_split('/[,;]+/u', $address) ?: [] as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 3 && ! preg_match('/\d/u', $part) && ($place = self::named($part, $region))) {
                return $place;
            }
        }

        return null;
    }
}
