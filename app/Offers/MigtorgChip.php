<?php

namespace App\Offers;

use App\Offers\Console\MigtorgArchive;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Support\FieldLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Чип «Мигторг» в шапке редактора и карточки строки — одно состояние на предложение с номером убытка:
 * `found` — лот нашёлся, ждёт «Это она» (номер из одних цифр, свои фото — сам не берётся); `running` — качается;
 * `done` — поля или кадры взяты; `searching` — лота нет, архив Мигторга ещё обходится; `missing` — архив пройден, лота
 * нет. Без номера или без входа на Мигторг — чипа нет: искать нечем.
 */
final class MigtorgChip
{
    private function __construct(
        public readonly string $state,
        public readonly string $ref,
        public readonly ?object $lot = null,
        public readonly ?array $progress = null,
        public readonly int $taken = 0,
        public readonly array $fields = [],
        public readonly bool $byHand = false,
        public readonly ?object $scan = null,
    ) {}

    public static function of(Offer $offer): ?self
    {
        if (! $offer->claim_ref_key || ! Migtorg::ready()) {
            return null;
        }
        $lot = ImportMigtorgLot::available($offer);
        if (! $lot) {
            $scan = MigtorgArchive::scan();

            return new self($scan?->done_at ? 'missing' : 'searching', (string) $offer->claim_ref, scan: $scan);
        }
        $photos = $offer->exists ? $offer->media()->where('collection_name', 'photos')->get() : collect();
        $taken = $photos->filter(fn ($m) => $m->getCustomProperty('migtorg'))->count();
        $byHand = $photos->contains(fn ($m) => ! $m->getCustomProperty('migtorg') && $m->getCustomProperty('unmarked') === 'migtorg');
        $fields = $offer->exists ? ($offer->events()->where('type', OfferEventType::Updated)->where('payload->source', 'migtorg')
            ->whereNotNull('payload->fields')->latest('id')->value('payload')['fields'] ?? []) : [];
        $progress = $offer->exists ? ImportMigtorgLot::progress($offer->id) : null;
        $state = match (true) {
            $progress !== null => 'running',
            $taken > 0 || $fields !== [] => 'done',
            default => 'found',
        };

        return new self($state, (string) $offer->claim_ref, $lot, $progress, $taken, $fields, $byHand);
    }

    /**
     * Флажок в строке списка: лот найден, а ничего не взято — видно, где нажать. Один запрос на страницу: номера
     * лотов, никем не взятых.
     */
    public static function waits(Offer $offer): bool
    {
        if (! $offer->claim_ref_key) {
            return false;
        }
        $free = once(fn () => Migtorg::ready() ? DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->whereNull('offer_id')->pluck('claim_ref_key')->flip() : collect());

        return $free->has($offer->claim_ref_key);
    }

    public function is(string ...$states): bool
    {
        return in_array($this->state, $states, true);
    }

    /** Подпись чипа: «Мигторг», «Мигторг 12 из 54», «Мигторг: ищем», «Нет на Мигторге». */
    public function label(): string
    {
        return match ($this->state) {
            'running' => 'Мигторг'.($this->counter() ? ' '.$this->counter() : ''),
            'searching' => 'Мигторг: ищем',
            'missing' => 'Нет на Мигторге',
            default => 'Мигторг',
        };
    }

    /** Ждёт человека — обычным текстом; всё остальное — приглушённо. */
    public function quiet(): bool
    {
        return ! $this->is('found', 'running');
    }

    public function title(): string
    {
        return $this->lot?->title ?: $this->ref;
    }

    /** Кнопка шторки и что она делает (`act`): подтвердить лот, докачать кадры, проверить индекс ещё раз. */
    public function action(): ?array
    {
        return match (true) {
            $this->is('found') => ['take', $this->byHand ? 'Это она, взять данные' : 'Это она, взять фото и данные'],
            $this->is('done') && ! $this->byHand && $this->lot->photos && $this->taken < $this->lot->photos => ['take', $this->taken ? 'Докачать фото' : 'Взять фото'],
            $this->is('missing') => ['recheck', 'Проверить ещё раз'],
            default => null,
        };
    }

    /** «12 из 54»; пока карточка лота не открыта — пусто. */
    public function counter(): ?string
    {
        return ($this->progress['n'] ?? null) ? min($this->progress['i'] + 1, $this->progress['n']).' из '.$this->progress['n'] : null;
    }

    /** Строки шторки: подпись → значение, пустые не рисуются. */
    public function rows(): array
    {
        if (! $this->lot) {
            $reached = $this->scan?->reached_at ? Carbon::parse($this->scan->reached_at)->translatedFormat('j M') : null;

            // Номер — в заголовке шторки: строкой ниже он только повторился бы.
            return array_filter([
                $this->is('missing') ? 'Проверено с' : 'Проверено до' => $this->is('missing')
                    ? ($this->scan?->floor_date ? Carbon::parse($this->scan->floor_date)->translatedFormat('j M') : null)
                    : $reached,
            ]);
        }

        return array_filter([
            'Номер' => $this->lot->claim_ref,
            'VIN' => $this->lot->vin,
            'Город' => $this->lot->city ?? null,
            'Торги' => $this->ends(),
            'Фото' => $this->photos(),
            'Взяты' => $this->fields ? FieldLabels::list(array_values(array_diff($this->fields, ['answer_by']))) : null,
        ]);
    }

    private function ends(): ?string
    {
        if (! $this->lot->ends_at) {
            return null;
        }
        $at = Carbon::parse($this->lot->ends_at);

        return $at->isPast() ? 'закончились '.$at->translatedFormat('j M') : 'до '.$at->translatedFormat('j M, H:i');
    }

    private function photos(): ?string
    {
        return match (true) {
            (bool) $this->counter() => $this->counter(),
            $this->byHand => 'загружены руками',
            (bool) $this->lot->photos => $this->taken && $this->taken < $this->lot->photos ? "{$this->taken} из {$this->lot->photos}" : (string) $this->lot->photos,
            default => $this->taken ? (string) $this->taken : null,
        };
    }
}
