<?php

namespace App\Offers;

use App\Offers\Documents\SignedContractPdf;
use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Оригинал документа сделки (26.11.2026, `Originals`): что (`kind`, `title`), куда должен попасть (`to`: `us` — нам, `owner` —
 * прежнему владельцу, `supplier` — поставщику), где сейчас (`state`: `waiting` — ещё у того, от кого ждём, или не отправили,
 * `at_us` — у нас, `sent` — едет СДЭКом, `done` — дошёл, `skip` — не нужен), трек СДЭК; у пакета Р.О.С. и ПРАЙМ — ещё скан
 * (`needs_scan`, коллекция `scan`; у ДКП с покупателем скан — подписанный договор сделки).
 */
#[Fillable(['deal_id', 'kind', 'title', 'to', 'state', 'track', 'needs_scan', 'sent_at', 'done_at', 'updated_by'])]
final class Original extends Model implements HasMedia
{
    use FastAttributes;
    use InteractsWithMedia;

    protected $table = 'deal_originals';

    /**
     * От кого ждём документ, пока он не у нас (`waiting`): договор комиссии или доверенность — у исполнителя вывоза (подписывает
     * страхователь при вывозе), ДКП пакета Р.О.С. — у менеджера (подписывают у поставщика и с покупателем), счёт-фактура — у
     * поставщика. Остальное — наше: ещё не отправили.
     */
    public const FROM = [
        'commission' => 'executor', 'proxy' => 'executor',
        'supplier_dkp' => 'manager', 'buyer_dkp' => 'manager',
        'supplier_invoice' => 'supplier',
    ];

    private const HOLDER = ['executor' => 'у исполнителя вывоза', 'manager' => 'у менеджера', 'supplier' => 'у поставщика'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime', 'needs_scan' => 'bool'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('scan')->useDisk('private');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** От кого ждём, пока не у нас: `executor`, `manager`, `supplier`; null — отправляем мы. */
    public function from(): ?string
    {
        return self::FROM[$this->kind] ?? null;
    }

    /** Дошёл, куда надо (без скана): документ нам — у нас, остальным — у адресата; «не нужен» — тоже. */
    public function placed(): bool
    {
        return in_array($this->state, ['done', 'skip'], true) || ($this->to === 'us' && $this->state === 'at_us');
    }

    /** На месте: дошёл и скан есть, если нужен. */
    public function settled(): bool
    {
        return $this->state === 'skip' || ($this->placed() && (! $this->needs_scan || $this->scans()->isNotEmpty()));
    }

    /**
     * Сканы документа: свои, у ДКП с покупателем — и подписанный договор сделки (`SignedContractPdf::files`: его прикладывают
     * менеджер и «Ждём договор», второго места для скана нет). @return Collection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>
     */
    public function scans(): Collection
    {
        $own = collect($this->getMedia('scan')->all());

        return $this->kind === 'buyer_dkp' && $this->deal ? $own->concat(SignedContractPdf::files($this->deal)) : $own;
    }

    /** Ждём менеджера: документ ещё у него. */
    public function waitsManager(): bool
    {
        return $this->from() === 'manager' && $this->state === 'waiting';
    }

    /**
     * Наше дело по документу: `send` — отправить (ДКП прежнему владельцу), `executor` — получить у исполнителя вывоза, `scan` —
     * дошёл, а скана нет; null — ждём или на месте.
     */
    public function todo(): ?string
    {
        return match (true) {
            $this->state === 'skip' => null,
            $this->to !== 'us' && in_array($this->state, ['waiting', 'at_us'], true) => 'send',
            $this->from() === 'executor' && $this->state === 'waiting' => 'executor',
            $this->placed() && ! $this->settled() => 'scan',
            default => null,
        };
    }

    /** Где сейчас — словами строки («у исполнителя вывоза», «едет к нам СДЭКом», «у прежнего владельца»). */
    public function place(): string
    {
        return match ($this->state) {
            'waiting' => self::HOLDER[$this->from() ?? ''] ?? ($this->to === 'us' ? 'ещё не у нас' : 'ещё не отправили'),
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

    /** Состояния выбора в шторке: значение → слова (документу нам — свои, документу от нас — свои). @return array<string, string> */
    public function choices(): array
    {
        if ($this->to === 'us') {
            return ['waiting' => mb_ucfirst(self::HOLDER[$this->from() ?? ''] ?? 'ещё не у нас'), 'sent' => 'Едет к нам СДЭКом', 'at_us' => 'У нас', 'skip' => 'Не нужен'];
        }

        return ['waiting' => 'Ещё не отправили', 'sent' => 'Отправили СДЭКом', 'done' => $this->to === 'owner' ? 'У прежнего владельца' : 'У поставщика', 'skip' => 'Не нужен'];
    }
}
