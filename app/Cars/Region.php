<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Регион России: номер — как на номерах машин (50 — Московская область), ключ ОКТМО (две цифры, автономный округ
 * внутри области — пять), имя полное и короткое («Моск. обл.»), `match` — как его пишут в адресах (регулярка без
 * границ). Данные — database/data/regions.csv, грузит `SettlementImport`.
 */
#[Fillable(['oktmo', 'plate', 'name', 'short', 'match'])]
class Region extends Model
{
    public $timestamps = false;

    /**
     * Федеральные округа по номеру региона (22.10.2026, выбор «Регионы» менеджера группами). ДНР, ЛНР, Запорожская и
     * Херсонская — в Южном, как у Росстата.
     */
    public const DISTRICTS = [
        'Центральный' => ['31', '32', '33', '36', '37', '40', '44', '46', '48', '50', '57', '62', '67', '68', '69', '71', '76', '77'],
        'Северо-Западный' => ['10', '11', '29', '35', '39', '47', '51', '53', '60', '78', '83'],
        'Южный' => ['01', '08', '23', '30', '34', '61', '80', '81', '82', '84', '85', '92'],
        'Северо-Кавказский' => ['05', '06', '07', '09', '15', '20', '26'],
        'Приволжский' => ['02', '12', '13', '16', '18', '21', '43', '52', '56', '58', '59', '63', '64', '73'],
        'Уральский' => ['45', '66', '72', '74', '86', '89'],
        'Сибирский' => ['04', '17', '19', '22', '24', '38', '42', '54', '55', '70'],
        'Дальневосточный' => ['03', '14', '25', '27', '28', '41', '49', '65', '75', '79', '87'],
    ];

    /** @var array<int, self>|null */
    private static ?array $all = null;

    /** Регионы поменяли (справочник, тест завёл свой) — память `inText` перечитается. */
    protected static function booted(): void
    {
        static::saved(fn () => self::$all = null);
        static::deleted(fn () => self::$all = null);
    }

    /** Федеральный округ региона словом: «Уральский». */
    public function district(): ?string
    {
        foreach (self::DISTRICTS as $name => $plates) {
            if (in_array(str_pad((string) $this->plate, 2, '0', STR_PAD_LEFT), $plates, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Регион машины — одно правило для продажи и вывоза: регион её города, иначе названный в адресе. Город — из базы по
     * id: связь, загруженная до смены города, отдала бы прежний.
     */
    public static function of(?int $settlementId, ?string $address): ?int
    {
        $city = $settlementId ? Settlement::whereKey($settlementId)->value('region_id') : null;

        return $city ?? self::inText($address)?->id;
    }

    /** Первый регион, названный в тексте адреса: «Пермский край, д. Ванюки», «Ярославская обл, р-н …». */
    public static function inText(?string $text): ?self
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        self::$all ??= self::all()->all();
        $best = null;
        foreach (self::$all as $region) {
            if (preg_match('/'.$region->match.'/u', $text, $m, PREG_OFFSET_CAPTURE) && ($best === null || $m[0][1] < $best[1])) {
                $best = [$region, $m[0][1]];
            }
        }

        return $best[0] ?? null;
    }
}
