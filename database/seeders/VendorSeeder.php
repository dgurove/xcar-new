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
    ];

    /** Договорные цены хранения в сутки по категориям — со слов владельца; заводятся один раз, пока у вендора нет прайса. */
    public const STORAGE_RATES = [
        'ВСК' => ['passenger' => 120, 'light' => 150, 'truck' => 180, 'long' => 200],
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
                    $vendor->tariffs()->create(['category' => $category, 'service' => TariffService::Storage, 'from_day' => 1, 'price' => $price, 'valid_from' => now()->toDateString()]);
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
