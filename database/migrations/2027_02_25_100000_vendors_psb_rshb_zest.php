<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Preset;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * 04.11.2026, владелец: Андрей пересылает на deal@ письма новых вендоров, а предложения не заводятся — вендор не узнан.
 * ПСБ Страхование (номера «0207-26-06», `CodeMatcher`; прежнее имя — СМП), РСХБ-Страхование и лизинг ЗЕСТ (номеров
 * убытка нет — «б/н», машину держат VIN и госномер). Маршрут — как у Т-Страхования: контакты владельца менеджеру, он
 * забирает, ДКП с собственником, подбор нам.
 */
return new class extends Migration
{
    private const VENDORS = [
        ['ПСБ Страхование', 'insurer', ['psbins.ru', 'smpins.ru'], false],
        ['РСХБ-Страхование', 'insurer', ['rshbins.ru'], false],
        ['ЗЕСТ', 'leasing', ['zest-leasing.ru', 'zest-leazing.ru'], true],
    ];

    public function up(): void
    {
        foreach (self::VENDORS as [$name, $kind, $senders, $noClaim]) {
            $vendor = Vendor::firstOrCreate(['name' => $name], [
                'kind' => $kind, 'deal_format' => 'direct', 'parser' => 'generic', 'senders' => $senders, 'claim_ref_optional' => $noClaim,
            ]);
            $workflow = $vendor->workflowOrNew(Track::Sale);
            if (! $workflow->stages()->exists()) {
                $workflow->update(['auto_start' => true, 'is_active' => true]);
                app(ApplyPreset::class)($workflow, Preset::TBank);
            }
        }
    }

    public function down(): void {}
};
