<?php

namespace App\Offers\Console;

use App\Mail\Direction;
use App\Mail\Message;
use App\Offers\Actions\FillFromLetter;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Разово: письма, что пришли к уже заведённым черновикам до `FillFromLetter` (13.10.2026), дописывают их пустые поля.
 * Без `--apply` — что изменится, списком (всё в откатываемой транзакции, документы не читаются).
 */
class FillFromLettersCommand extends Command
{
    protected $signature = 'offers:fill-from-letters {--days=7} {--apply}';

    protected $description = 'Письма к заведённым черновикам дописывают пустые поля';

    public function handle(FillFromLetter $fill): int
    {
        $apply = (bool) $this->option('apply');
        $letters = Message::query()->where('direction', Direction::In)->where('date_at', '>', now()->subDays((int) $this->option('days')))
            ->whereHas('thread', fn ($t) => $t->whereNotNull('offer_id')->whereHas('offer', fn ($o) => $o->where('state', OfferState::Draft)))
            ->with('thread', 'attachments')->orderBy('date_at')->get();
        $changed = 0;
        foreach ($letters as $letter) {
            $offer = Offer::find($letter->thread->offer_id);
            $before = $offer->only($offer->getFillable());
            $apply || DB::beginTransaction();
            try {
                $fill($offer, $letter, read: $apply);
                $diff = array_keys(array_diff_assoc(array_map('json_encode', $offer->fresh()->only($offer->getFillable())), array_map('json_encode', $before)));
            } catch (Throwable $e) {
                $diff = ['ошибка: '.$e->getMessage()];
            } finally {
                $apply || DB::rollBack();
            }
            if ($diff) {
                $changed++;
                $this->line("{$offer->number} {$offer->titleWithYear()} ← письмо {$letter->id}: ".implode(', ', $diff));
            }
        }
        $this->info(($apply ? 'Дописано' : 'Допишется').": {$changed} из {$letters->count()} писем");

        return self::SUCCESS;
    }
}
