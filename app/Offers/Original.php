<?php

namespace App\Offers;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оригинал документа сделки (26.11.2026, `Originals`): что (`title`), куда должен попасть (`to`: `supplier` — Совкомбанк, `owner` —
 * прежний владелец, `us` — нам), где сейчас (`state`: `waiting` — ещё не у нас, `at_us` — у нас, `sent` — едет СДЭКом, `done` —
 * дошёл, `skip` — не нужен), трек СДЭК.
 */
#[Fillable(['deal_id', 'kind', 'title', 'to', 'state', 'track', 'sent_at', 'done_at', 'updated_by'])]
final class Original extends Model
{
    use FastAttributes;

    protected $table = 'deal_originals';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** На месте: дошёл куда надо или не нужен; документ нам — достаточно «у нас». */
    public function settled(): bool
    {
        return in_array($this->state, ['done', 'skip'], true) || ($this->to === 'us' && $this->state === 'at_us');
    }

    /** Ход наш: документ у нас (или ещё ждём его к нам от исполнителя), а ехать ему дальше. */
    public function ours(): bool
    {
        return $this->to !== 'us' && in_array($this->state, ['waiting', 'at_us'], true);
    }

    /** Где сейчас — словами строки («у исполнителя вывоза», «едет СДЭКом», «у Совкомбанка»). */
    public function place(): string
    {
        return match ($this->state) {
            'waiting' => match ($this->kind) {
                'commission' => 'у исполнителя вывоза',
                'dkp_back' => 'у менеджера',
                default => 'ещё не отправили',
            },
            'at_us' => 'у нас',
            'sent' => $this->to === 'us' ? 'едет к нам СДЭКом' : 'едет СДЭКом',
            'done' => match ($this->to) {
                'supplier' => 'у поставщика',
                'owner' => 'у прежнего владельца',
                default => 'у нас',
            },
            'skip' => 'не нужен',
            default => $this->state,
        };
    }

    /** Состояния выбора в шторке: значение → слова (кому ехать — свои). @return array<string, string> */
    public function choices(): array
    {
        $done = match ($this->to) {
            'supplier' => 'У поставщика',
            'owner' => 'У прежнего владельца',
            default => null,
        };

        return array_filter([
            'waiting' => $this->kind === 'dkp_back' ? 'У менеджера' : ($this->kind === 'commission' ? 'У исполнителя вывоза' : 'Ещё не отправили'),
            'at_us' => 'У нас',
            'sent' => $this->to === 'us' ? 'Едет к нам СДЭКом' : 'Отправили СДЭКом',
            'done' => $done,
            'skip' => 'Не нужен',
        ]);
    }
}
