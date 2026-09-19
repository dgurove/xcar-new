<?php

namespace App\Workflow\Console;

use App\Mail\Scope;
use App\Mail\Template;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Database\Seeders\VendorSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перезалить боевые маршруты из заготовок: тексты живут в базе, и после
 * правки заготовки их иначе не обновить. Маршрут, на этапах которого стоят
 * предложения, не трогается — там правят руками.
 */
class RefillVendors extends Command
{
    protected $signature = 'vendors:refill';

    protected $description = 'Перезалить маршруты вендоров из заготовок (только свободные от предложений)';

    public function handle(ApplyPreset $apply): int
    {
        foreach (VendorSeeder::VENDORS as [$name, $senders, $parser, $sale, $autoPickup]) {
            if (! $sale) {
                continue;
            }
            $vendor = Vendor::firstOrCreate(['name' => $name]);
            foreach ([[Track::Sale, $sale], [Track::Service, Preset::Pickup]] as [$track, $preset]) {
                $workflow = $vendor->workflowOrNew($track);
                $ids = $workflow->stages()->pluck('workflow_stages.id');
                // Занятые этапы и ответы менеджеров по ним — история, её не сносим.
                if ($ids->isNotEmpty() && (Position::whereIn('stage_id', $ids)->exists() || Requirement::whereIn('stage_id', $ids)->exists())) {
                    $this->warn("{$name}: {$workflow->track->label()} — по этапам есть предложения, пропуск");

                    continue;
                }
                DB::transaction(function () use ($workflow, $preset, $apply) {
                    $workflow->blocks()->delete();
                    $workflow->unsetRelation('blocks');
                    $apply($workflow, $preset);
                });
                $this->info("{$name}: {$workflow->track->label()} — {$preset->label()}");
            }
        }

        // Письма — тоже из заготовки: перезаливка возвращает и их текст.
        foreach (Preset::Pickup->route()->letters() as $letter) {
            Template::where('scope', Scope::Offers)->where('name', $letter['name'])->update(['subject' => $letter['subject'], 'body' => $letter['body']]);
        }

        // Письма поставщику под старыми именами, на которые больше никто не ссылается.
        $orphans = Template::where('scope', Scope::Offers)->where('name', 'like', 'Поставщику:%')
            ->whereDoesntHave('stages')->get();
        foreach ($orphans as $template) {
            $template->delete();
            $this->line("Удалён шаблон «{$template->name}»");
        }

        return self::SUCCESS;
    }
}
