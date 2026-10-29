<?php

namespace App\Cars;

use App\Search\Term;
use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    use FastAttributes;

    /** Город — то, что ищется по одному слову без региона и типа (тема письма: «Победа» и «Восход» — посёлки, не города). */
    public const TOWN = 2;

    /** Как место пишут в адресе перед именем: «г.», «пгт», «д.», «пос.», «ст-ца». */
    private const TYPED = '/(?:^|[\s,(])(?:г|гор|город|пгт|рп|п|пос|посёлок|поселок|д|дер|деревня|с|село|ст-ца|станица|х|хутор|аул|сл|слобода|мкр)(?:\.\s*|\s+)([А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]*)*(?:\s[А-ЯЁ][а-яё\-]+){0,2})/u';

    /** @var array<string, ?array{id: int, name: string, title: string}> запрос → место справочника (`named`) */
    private static array $named = [];

    /**
     * Место по началу имени — поле «Город» и слова поиска. С `region` число в конце — номер региона, как на номерах машин
     * («никольское 47», «Москва, 77», просто «77» — крупные места региона); место-регион («Республика Татарстан», тип
     * `рег`) находится и по второму слову.
     */
    public function scopeSearch(Builder $q, string $term, bool $region = true): Builder
    {
        $term = mb_strtolower(trim($term));
        $code = $region && preg_match('/(?:^|[\s,])(\d{1,3})$/u', $term, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : null;
        $name = $region ? trim((string) preg_replace('/(?:^|[\s,])\d{1,3}$/u', '', $term), ' ,') : $term;
        $like = Term::like($name, prefix: true);

        return $q->when($name !== '', fn ($s) => $s->where(fn ($w) => $w->whereRaw('lower(settlements.name) like ?', [$like])
            ->orWhere(fn ($r) => $r->where('settlements.type', 'рег')->whereRaw('lower(settlements.name) like ?', ['% '.$like]))))
            ->when($code, fn ($s) => $s->where('settlements.region_code', $code));
    }

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
     * Место по имени из источника, что знает и регион (лот Мигторга: «Кугульта», «Ставропольский край»): с регионом —
     * любое место региона, сёла тоже (15.10.2026: Кугульта — село, и без региона её не было; сёл с этим именем два).
     * Имя с типом («г Челябинск», «с. Кугульта») — без типа. Село без региона и типа не угадываем.
     *
     * @return array{id: int, name: string, title: string}|null
     */
    public static function resolve(?string $name, ?string $region = null): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $area = $region ? Region::inText($region) : null;
        if ($place = self::named($name, $area)) {
            return $place;
        }
        // Имя с типом — без типа; не через адрес «регион, имя»: в справочнике есть и сами регионы, и неизвестное село
        // находилось бы «Ставропольским краем».
        if (preg_match(self::TYPED, ' '.$name, $m)) {
            return self::named($m[1], $area, villages: true);
        }

        return null;
    }

    /**
     * Свободное место — текст как есть (тип `своб`, ранг 9): справочник его не знает, а пусто хуже (владелец, 15.10.2026).
     * Регион — из «, 26» в конце имени (поле «Город», `ReferenceController::createSettlement`) или словами (лот Мигторга).
     *
     * @return array{id: int, name: string, title: string}
     */
    public static function free(string $name, ?string $region = null): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' ,');
        $plate = preg_match('/[\s,]+(\d{1,3})$/u', $name, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : null;
        $name = $plate ? trim((string) preg_replace('/[\s,]+\d{1,3}$/u', '', $name)) : $name;
        $area = $plate ? Region::where('plate', $plate)->first() : ($region ? Region::inText($region) : null);
        $place = self::firstOrCreate(['name' => $name, 'type' => 'своб', 'region_code' => $area?->plate], ['region_id' => $area?->id, 'rank' => 9]);

        return ['id' => $place->id, 'name' => $place->name, 'title' => $place->title()];
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
