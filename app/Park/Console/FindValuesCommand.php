<?php

namespace App\Park\Console;

use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\AutoScan;
use App\Mail\Scan\Files;
use App\Mail\Scan\ScanFile;
use App\Mail\Scan\VehicleSubject;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Оценочная у машин парковки, у которых её нет (12.10.2026, владелец: «пройтись по всем тачкам без оценочной и поискать её в
 * фотографиях и документах»). Чтение — очередью `scan` (`AutoScan`: найденное встаёт в пустое поле, в истории — «Из
 * документов»). Без ключей — только список, сколько чего читать; `--apply` — документы; `--photos` — и фото тех, у кого после
 * документов пусто (сфотографированный бланк, не узнанный как скан); `--report` — что нашли и откуда.
 */
class FindValuesCommand extends Command
{
    protected $signature = 'park:find-values {--apply : прочитать документы} {--photos : и фото} {--report : что нашли}';

    protected $description = 'Найти оценочную стоимость в документах и фото машин парковки';

    public function handle(): int
    {
        if ($this->option('report')) {
            return $this->report();
        }
        $vehicles = Vehicle::withoutGlobalScope(City::SCOPE)->whereNull('value')
            ->whereIn('state', [VehicleState::Expected, VehicleState::Stored])->with(['brand', 'model', 'vendor'])->orderBy('id')->get();
        $queued = 0;
        foreach ($vehicles as $v) {
            $files = (new VehicleSubject($v))->files();
            $docs = Files::rank($files);
            $photos = $files->filter(fn (ScanFile $f) => $f->isPhoto() && ! DocumentText::isCached($f))->take(20);
            $this->line("ТС {$v->id} {$v->titleWithYear()} ({$v->vendor?->name}): документов {$docs->count()}, непрочитанных фото {$photos->count()}");
            if ($this->option('photos') && $photos->isNotEmpty()) {
                AutoScan::start($v, $photos->map->scanId()->values()->all());
                $queued++;
            } elseif ($this->option('apply') && $docs->isNotEmpty()) {
                AutoScan::start($v);
                $queued++;
            }
        }
        $this->info("без оценочной: {$vehicles->count()}".($queued ? ", в чтение: {$queued}" : ($this->option('apply') || $this->option('photos') ? '' : '; --apply — читать документы, --photos — фото')));

        return self::SUCCESS;
    }

    /** Нашли — сумма и документ из записи истории, не нашли — список. */
    private function report(): int
    {
        $vehicles = Vehicle::withoutGlobalScope(City::SCOPE)->whereIn('state', [VehicleState::Expected, VehicleState::Stored])
            ->with(['brand', 'model', 'vendor', 'events' => fn ($e) => $e->where('type', EventType::FilledFromDocs)->whereDate('created_at', today())])->orderBy('id')->get();
        $found = $vehicles->filter(fn ($v) => $v->value && $v->events->contains(fn ($e) => in_array('value', $e->payload['fields'] ?? [], true)));
        foreach ($found as $v) {
            $event = $v->events->first(fn ($e) => in_array('value', $e->payload['fields'] ?? [], true));
            $this->line("+ ТС {$v->id} {$v->titleWithYear()} ({$v->vendor?->name}): ".Money::rub($v->value).' — '.implode(', ', $event->payload['sources'] ?? []));
        }
        $missing = $vehicles->whereNull('value');
        foreach ($missing as $v) {
            $this->line("- ТС {$v->id} {$v->titleWithYear()} ({$v->vendor?->name})");
        }
        $this->info("нашли сегодня: {$found->count()}, нет оценочной: {$missing->count()}");

        return self::SUCCESS;
    }
}
