<?php

declare(strict_types=1);

namespace App\Cars\Vin;

use RuntimeException;

/**
 * Доступ к справочникам VIN из resources/vin.
 *
 * Справочники собираются скриптами из xcar.ru/vin-decoder (список WMI и разбор
 * VDS по маркам — из Wikibooks, коды стран — по ISO 3780) и лежат в JSON, чтобы
 * их можно было обновлять, не трогая код. Файлы читаются один раз за запрос.
 */
class VinTables
{
    /** @var array<string, array> */
    private array $cache = [];

    public function __construct(private readonly ?string $path = null) {}

    /** @return array<string, array{manufacturer?: string, brand?: string}> */
    public function wmi(): array
    {
        return $this->load('wmi.json')['wmi'] ?? [];
    }

    /** @return array<string, string> */
    public function countries(): array
    {
        return $this->load('countries.json');
    }

    /** @return array<string, string> */
    public function countriesRu(): array
    {
        return $this->load('countries_ru.json')['names'] ?? [];
    }

    /** @return array<string, string> */
    public function regions(): array
    {
        return $this->load('regions.json');
    }

    /** @return array<string, string> */
    public function regionsRu(): array
    {
        return $this->load('countries_ru.json')['regions'] ?? [];
    }

    /** @return array<string, list<string>> */
    public function brandAliases(): array
    {
        return $this->load('brand_aliases.json')['aliases'] ?? [];
    }

    /** @return array<string, string> */
    public function bodiesRu(): array
    {
        return $this->load('bodies_ru.json')['names'] ?? [];
    }

    /** @return array<string, list<int>> */
    public function yearCodes(): array
    {
        return $this->load('year_codes.json')['codes'] ?? [];
    }

    /** @return array<string, array> */
    public function schemes(): array
    {
        return $this->load('schemes.json')['schemes'] ?? [];
    }

    /** @return array<string, array> */
    public function engines(): array
    {
        return $this->load('engines.json')['engines'] ?? [];
    }

    /** @return array<string, string> */
    public function wikibooksIndex(): array
    {
        return $this->load('vds/_wikibooks_index.json')['wmi'] ?? [];
    }

    /** @return array<string, string> */
    public function wikibooksBrands(): array
    {
        return $this->load('vds/_wikibooks_index.json')['brand'] ?? [];
    }

    public function vdsTable(string $name): array
    {
        return $this->load("vds/{$name}.json");
    }

    /**
     * Самый длинный подходящий префикс WMI выигрывает: XTA важнее, чем X.
     */
    public function schemeFor(string $wmi): array
    {
        $schemes = $this->schemes();

        foreach ([3, 2, 1] as $length) {
            $found = $schemes[substr($wmi, 0, $length)] ?? null;
            if ($found !== null) {
                return $found;
            }
        }

        return [];
    }

    public function countryFor(string $wmi): ?string
    {
        $title = $this->countries()[substr($wmi, 0, 2)] ?? null;

        return $title === null ? null : ($this->countriesRu()[$title] ?? $title);
    }

    public function regionFor(string $wmi): ?string
    {
        foreach ($this->regions() as $chars => $title) {
            // Ключи вида «123457» PHP приводит к int — возвращаем к строке
            if (str_contains((string) $chars, $wmi[0])) {
                return $this->regionsRu()[$title] ?? $title;
            }
        }

        return null;
    }

    private function load(string $file): array
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }

        $full = ($this->path ?? resource_path('vin')).'/'.$file;
        if (! is_file($full)) {
            return $this->cache[$file] = [];
        }

        $decoded = json_decode((string) file_get_contents($full), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Справочник {$file} повреждён");
        }

        return $this->cache[$file] = $decoded;
    }
}
