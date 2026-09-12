<?php

namespace Database\Seeders;

use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Insurer;
use App\Workflow\Preset;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Seeder;

/**
 * Три боевые страховые с маршрутами. Имена — как их называет разбор писем
 * (`Extractor::INSURERS`), иначе кандидаты из почты не привяжутся.
 * Идемпотентен: непустой маршрут не трогается, правки руками остаются.
 */
class InsurerSeeder extends Seeder
{
    private const INSURERS = [
        // имя, продажа, вывоз с каждым предложением
        ['Т-Страхование', Preset::TBank, false],
        ['АльфаСтрахование', Preset::Alfa, false],
        // Совкомбанк обязывает вывозить каждую машину — вывоз идёт параллельно продаже.
        ['Совкомбанк Страхование', Preset::Sovcombank, true],
    ];

    public function run(ApplyPreset $apply): void
    {
        foreach (self::INSURERS as [$name, $sale, $autoPickup]) {
            $insurer = Insurer::firstOrCreate(['name' => $name]);
            $this->fill($insurer->workflowOrNew(Track::Sale), $sale, true, $apply);
            $this->fill($insurer->workflowOrNew(Track::Service), Preset::Pickup, $autoPickup, $apply);
        }
    }

    private function fill(Workflow $workflow, Preset $preset, bool $autoStart, ApplyPreset $apply): void
    {
        if ($workflow->stages()->exists()) {
            return;
        }
        $workflow->update(['auto_start' => $autoStart]);
        $apply($workflow, $preset);
        $this->command?->info("{$workflow->insurer->name}: {$workflow->track->label()} — {$preset->label()}");
    }
}
