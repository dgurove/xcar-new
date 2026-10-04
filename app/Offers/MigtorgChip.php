<?php

namespace App\Offers;

use App\Offers\Jobs\ImportMigtorgLot;
use App\Support\FieldLabels;
use Illuminate\Support\Carbon;

/**
 * Чип «Мигторг» в шапке редактора и карточки строки: лот по номеру убытка, ход загрузки, что уже взято. Лота нет
 * (или он о другой машине, или вход не задан) — чипа нет.
 */
final class MigtorgChip
{
    public function __construct(
        public readonly object $lot,
        public readonly ?array $progress,
        public readonly int $taken,
        public readonly array $fields,
        public readonly bool $manual = false,
    ) {}

    public static function of(Offer $offer): ?self
    {
        if (! $offer->claim_ref_key || ! ($lot = ImportMigtorgLot::available($offer))) {
            return null;
        }
        $taken = $offer->media()->where('collection_name', 'photos')->whereNotNull('custom_properties->migtorg')->count();
        $fields = $offer->events()->where('type', OfferEventType::Updated)->where('payload->source', 'migtorg')
            ->whereNotNull('payload->fields')->latest('id')->value('payload')['fields'] ?? [];

        // Кадры Мигторга, загруженные руками (знак снят при приёме, uuid лота нет): докачка их задвоила бы.
        $manual = $offer->media()->where('collection_name', 'photos')->whereNull('custom_properties->migtorg')->where('custom_properties->unmarked', 'migtorg')->exists();

        return new self($lot, ImportMigtorgLot::progress($offer->id), $taken, $fields, $manual);
    }

    public function running(): bool
    {
        return $this->progress !== null;
    }

    /** «12 из 54», пока карточка не открыта — пусто. */
    public function counter(): ?string
    {
        return $this->running() && ($this->progress['n'] ?? null) ? ($this->progress['i'] + 1).' из '.$this->progress['n'] : null;
    }

    /** Всё взято — чип приглушён: он только знак, откуда фото и поля. */
    public function done(): bool
    {
        return ! $this->running() && ($this->taken > 0 || $this->fields || $this->manual) && ! $this->action();
    }

    /** Кнопка шторки: «Взять фото» — кадров лота в ряду нет, «Докачать фото» — взялись не все. */
    public function action(): ?string
    {
        return match (true) {
            $this->running(), $this->manual => null,
            $this->taken === 0 => 'Взять фото',
            $this->lot->photos && $this->taken < $this->lot->photos => 'Докачать фото',
            default => null,
        };
    }

    public function ends(): ?string
    {
        if (! $this->lot->ends_at) {
            return null;
        }
        $at = Carbon::parse($this->lot->ends_at);

        return $at->isPast() ? 'закончились' : $at->translatedFormat('j M, H:i');
    }

    public function photos(): string
    {
        return match (true) {
            (bool) $this->counter() => $this->counter(),
            (bool) $this->lot->photos => $this->taken && $this->taken < $this->lot->photos ? "{$this->taken} из {$this->lot->photos}" : (string) $this->lot->photos,
            default => $this->taken ? (string) $this->taken : '',
        };
    }

    /** Взятые поля словами; «ответ до» — тот же конец торгов, что «срок от вендора». */
    public function fieldsText(): string
    {
        return $this->fields ? FieldLabels::list(array_values(array_diff($this->fields, ['answer_by']))) : '';
    }
}
