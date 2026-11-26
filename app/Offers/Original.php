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
 * (`needs_scan`, коллекция `scan`; у ДКП с покупателем скан — подписанный договор сделки). `history` — кто и когда что отметил.
 */
#[Fillable(['deal_id', 'kind', 'title', 'to', 'state', 'track', 'needs_scan', 'history', 'sent_at', 'done_at', 'updated_by'])]
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
        return ['sent_at' => 'datetime', 'done_at' => 'datetime', 'needs_scan' => 'bool', 'history' => 'array'];
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
        // Один раз на экземпляр (ревью 26.11.2026): строка вкладки, `settled` и `todo` спрашивают по нескольку раз.
        return $this->scanCache ??= (function () {
            $own = collect($this->getMedia('scan')->all());

            return $this->kind === 'buyer_dkp' && $this->deal ? $own->concat(SignedContractPdf::files($this->deal)) : $own;
        })();
    }

    /** @var Collection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>|null */
    private ?Collection $scanCache = null;

    /** Дней после отправки ДКП прежнему владельцу, когда пора проверить, дошёл ли он (СДЭК по России — до недели). */
    public const DELIVERY_DAYS = 7;

    /** Ждём менеджера: документ ещё у него. */
    public function waitsManager(): bool
    {
        return $this->from() === 'manager' && $this->state === 'waiting';
    }

    /**
     * Наше дело по документу: `send` — отправить (ДКП прежнему владельцу), `executor` — получить у исполнителя вывоза, `delivery` —
     * отправили от нас неделю назад, а «дошёл» не отмечен (проверить по треку), `scan` — дошёл, а скана нет; null — ждём или на месте.
     */
    public function todo(): ?string
    {
        return match (true) {
            $this->state === 'skip' => null,
            $this->to !== 'us' && in_array($this->state, ['waiting', 'at_us'], true) => 'send',
            $this->from() === 'executor' && $this->state === 'waiting' => 'executor',
            $this->to !== 'us' && $this->state === 'sent' && $this->sent_at?->lte(now()->subDays(self::DELIVERY_DAYS)) => 'delivery',
            $this->placed() && ! $this->settled() => 'scan',
            default => null,
        };
    }

    /** Дописать в историю (`history`): `['state' => …, 'track' => …]` или `['scan' => true|false]`; кто и когда — здесь. */
    public function note(array $entry, ?int $by): void
    {
        $this->history = [...($this->history ?? []), [...$entry, 'at' => now()->toIso8601String(), 'by' => $by]];
    }

    /** С какого времени документ там, где он сейчас: последняя запись о месте, у старых строк — отправка, приход, создание. */
    public function since(): ?\Illuminate\Support\Carbon
    {
        $last = collect($this->history ?? [])->last(fn (array $h) => isset($h['state']));

        return $last ? \Illuminate\Support\Carbon::parse($last['at']) : match ($this->state) {
            'sent' => $this->sent_at,
            'at_us', 'done' => $this->done_at,
            default => $this->created_at,
        };
    }

    /** Запись истории словами: «Отправили СДЭКом, трек …», «У нас», «Приложен скан». */
    public function said(array $h): string
    {
        if (isset($h['scan'])) {
            return $h['scan'] ? 'Приложен скан' : 'Убран скан';
        }
        $word = $this->choices()[$h['state']] ?? $h['state'];

        return $h['state'] === 'sent' && ! empty($h['track']) ? $word.', '.$h['track'] : $word;
    }

    /**
     * Значок состояния (строка шага и вкладка «Оригиналы»): на месте — галочка, едет — грузовик, дошёл без скана — скрепка цветом
     * «срочно», не нужен — перечёркнутый круг, иначе — файл. @return array{0: string, 1: string}
     */
    public function icon(): array
    {
        return match (true) {
            $this->state === 'skip' => ['ban', 'text-ink-dim'],
            $this->settled() => ['check-circle', 'text-open'],
            $this->placed() => ['clip', 'text-urgent'],
            $this->state === 'sent' => ['truck', 'text-word'],
            default => ['file', 'text-ink-dim'],
        };
    }

    /**
     * Следующий шаг — кнопками под документом (26.11.2026, вариант «Г»): `state` — куда отметить, `track` — с полем трека в
     * строке, `main` — лаймовая. Документ нам: «У нас», пока не дошёл, и «Едет СДЭКом» с треком, пока не отправили; документ от
     * нас: трек и «Отправили», потом «Вручено». Сканы и редкое («Не нужен», вернуть назад) — не здесь.
     *
     * @return list<array{state: string, label: string, main: bool, track?: bool}>
     */
    public function actions(): array
    {
        if ($this->to === 'us') {
            return match ($this->state) {
                'waiting' => [['state' => 'at_us', 'label' => 'У нас', 'main' => true], ['state' => 'sent', 'label' => 'Едет СДЭКом', 'main' => false, 'track' => true]],
                'sent' => [['state' => 'at_us', 'label' => 'У нас', 'main' => true]],
                default => [],
            };
        }

        return match ($this->state) {
            'waiting', 'at_us' => [['state' => 'sent', 'label' => 'Отправили', 'main' => true, 'track' => true]],
            'sent' => [['state' => 'done', 'label' => 'Вручено', 'main' => true]],
            default => [],
        };
    }

    /** Редкие состояния — в меню «⋯» документа: всё, чего нет ни сейчас, ни кнопками, кроме «Едет» (оно с треком). @return array<string, string> */
    public function rare(): array
    {
        $shown = [$this->state, 'sent', ...array_column($this->actions(), 'state')];

        return array_diff_key($this->choices(), array_flip($shown));
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

    /** Все состояния документа (история словами, меню «⋯» — `rare`): значение → слова (документу нам — свои, документу от нас — свои). @return array<string, string> */
    public function choices(): array
    {
        if ($this->to === 'us') {
            return ['waiting' => mb_ucfirst(self::HOLDER[$this->from() ?? ''] ?? 'ещё не у нас'), 'sent' => 'Едет к нам СДЭКом', 'at_us' => 'У нас', 'skip' => 'Не нужен'];
        }

        return ['waiting' => 'Ещё не отправили', 'sent' => 'Отправили СДЭКом', 'done' => $this->to === 'owner' ? 'Вручено прежнему владельцу' : 'Вручено поставщику', 'skip' => 'Не нужен'];
    }
}
