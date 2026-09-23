<?php

namespace Database\Seeders;

use App\Vendors\Parser;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Preset;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Seeder;

/**
 * Вендоры, которые пишут нам: адреса и домены отправителей, шаблон разбора,
 * у трёх боевых — маршруты. Идемпотентен: непустой маршрут не трогается,
 * заполненные адреса не перезаписываются — правки руками остаются.
 */
class VendorSeeder extends Seeder
{
    /** имя, отправители, разбор, маршрут продажи, вывоз с каждым предложением */
    public const VENDORS = [
        ['Т-Страхование', ['tinsurance.ru', 'tinkoffinsurance.ru', 'tbank.ru'], Parser::Tinkoff, Preset::TBank, false],
        ['АльфаСтрахование', ['alfastrah.ru'], Parser::Generic, Preset::Alfa, false],
        // Совкомбанк обязывает вывозить каждую машину — вывоз идёт параллельно продаже.
        ['Совкомбанк Страхование', ['sovcomins.ru'], Parser::Sovcombank, Preset::Sovcombank, true],
        ['Энергогарант', ['msk-garant.ru', 'energogarant.ru'], Parser::Energogarant, null, false],
        ['ИНСАЙТ', ['insightins.ru'], Parser::Insight, null, false],
        ['Абсолют Страхование', ['absolutins.ru'], Parser::Generic, null, false],
        ['ВСК', ['vsk.ru'], Parser::Generic, null, false],
        ['СОГАЗ', ['sogaz.ru'], Parser::Generic, null, false],
    ];

    /**
     * Договорные цены хранения в сутки: число — ставка на категорию, список — ступени «от заявленной
     * стоимости и выше». Заводятся один раз, пока у вендора нет прайса; дальше правятся в его карточке.
     */
    public const STORAGE_RATES = [
        'ВСК' => ['passenger' => 120, 'light' => 150, 'truck' => 180, 'long' => 200],
        // Договор АльфаСтрахования (без НДС): легковые — по заявленной стоимости, остальной транспорт — по типу.
        'АльфаСтрахование' => [
            'passenger' => [[0, 250], [500_000, 300], [1_500_000, 350], [3_000_000, 400], [5_000_000, 450]],
            'light' => 450, 'long' => 800, 'truck' => 900, 'trailer' => 900,
        ],
    ];

    public function run(ApplyPreset $apply): void
    {
        foreach (self::VENDORS as [$name, $senders, $parser, $sale, $autoPickup]) {
            $vendor = Vendor::firstOrCreate(['name' => $name]);
            if (! $vendor->senders) {
                $vendor->update(['senders' => $senders, 'parser' => $parser]);
            }
            if ($sale) {
                $this->fill($vendor->workflowOrNew(Track::Sale), $sale, true, $apply);
                $this->fill($vendor->workflowOrNew(Track::Service), Preset::Pickup, $autoPickup, $apply);
            }
            if (isset(self::STORAGE_RATES[$name]) && ! $vendor->tariffs()->exists()) {
                foreach (self::STORAGE_RATES[$name] as $category => $price) {
                    foreach (is_array($price) ? $price : [[null, $price]] as [$from, $rate]) {
                        $vendor->tariffs()->create(['category' => $category, 'service' => TariffService::Storage, 'from_day' => 1,
                            'from_value' => $from, 'price' => $rate, 'valid_from' => now()->toDateString()]);
                    }
                }
                $this->command?->info("{$name}: прайс хранения");
            }
        }
    }

    private function fill(Workflow $workflow, Preset $preset, bool $autoStart, ApplyPreset $apply): void
    {
        if ($workflow->stages()->exists()) {
            return;
        }
        $workflow->update(['auto_start' => $autoStart]);
        $apply($workflow, $preset);
        $this->command?->info("{$workflow->vendor->name}: {$workflow->track->label()} — {$preset->label()}");
    }
}
