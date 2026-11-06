<?php

namespace App\Park\Console;

use App\Mail\Actions\FreezeMessages;
use App\Mail\Attachment;
use App\Mail\Extraction\AttachmentClassifier;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\LettersPurge;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;

/**
 * Вернуть документы выданных ТС из ящика (06.11.2026, владелец: «нам очень нужны эти документы» — их стёрла очистка при
 * выдаче). Только документы, без фото (их 10 ГБ, диск не выдержит). У ТС, чей месяц выдачи ещё не закрыт и не оплачен
 * (`LettersPurge::due` ложно), письма размораживаются, документы закрепляются из ящика и встают в бумаги ТС
 * (`ImportThreadFiles` с `documentsOnly`) — свежие месяцы выдачи первыми. Без `--apply` — только посчитать.
 */
class RestoreLetterDocsCommand extends Command
{
    protected $signature = 'park:restore-letter-docs {--apply : разморозить и поставить загрузку} {--min-free=3 : не начинать, если на диске свободно меньше, ГБ}';

    protected $description = 'Вернуть из ящика документы выданных ТС, чей месяц ещё не закрыт';

    public function handle(FreezeMessages $freeze, AttachmentClassifier $classifier): int
    {
        $free = disk_free_space(storage_path()) / 1024 ** 3;
        if ($this->option('apply') && $free < (float) $this->option('min-free')) {
            $this->error(sprintf('На диске свободно %.1f ГБ — меньше %s, не начинаю', $free, $this->option('min-free')));

            return self::FAILURE;
        }
        $vehicles = Vehicle::with('vendor')->where('state', VehicleState::Released)->orderByDesc('released_at')->get()
            ->reject(fn (Vehicle $v) => LettersPurge::due($v));
        $threads = Thread::park()->whereIn('vehicle_id', $vehicles->modelKeys())->get(['id', 'vehicle_id']);
        $order = $vehicles->pluck('id')->flip();
        $threads = $threads->sortBy(fn ($t) => $order[$t->vehicle_id] ?? PHP_INT_MAX)->values();
        $messages = Message::whereIn('thread_id', $threads->pluck('id'))->pluck('id');
        $docs = collect($classifier->classify(Attachment::whereIn('message_id', $messages)->where('is_inline', false)->get())['documents']);
        $this->line(sprintf('ТС: %d, веток: %d, писем: %d, документов: %d (%.0f МБ), свободно на диске %.1f ГБ',
            $vehicles->count(), $threads->count(), $messages->count(), $docs->count(), $docs->sum('size') / 1024 ** 2, $free));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $thawed = $freeze->thaw(Message::whereIn('id', $messages)->whereNotNull('frozen_at')->pluck('id')->all());
        foreach ($threads as $t) {
            ImportThreadFiles::dispatch($t->id, null, null, true);
        }
        $this->info("Разморожено писем: {$thawed}, поставлено веток: {$threads->count()}");

        return self::SUCCESS;
    }
}
