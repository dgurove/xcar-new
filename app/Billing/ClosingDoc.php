<?php

namespace App\Billing;

use App\Mail\AddressKind;
use App\Support\Ago;
use App\Support\Docs;
use App\Support\Money;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Строка «Счетов и актов» (05.11.2026, владелец: «не разделять по месяцам, а группировать; логотипов вендоров не хватает;
 * кнопки просмотра и скачивания»): документ плательщика за месяц — живой (группа `Closing::items`) или отправленный
 * (счёт). Одно место, где решено, как документ называется, чей у него логотип, какие у него файлы и что с ним сейчас —
 * список, карточка и страница берут отсюда.
 */
final class ClosingDoc
{
    /** Ключ строки для карточки рядом (`Detail`): отправленный — `i{id}`, живой — `d{ГГГГММ}-{группа base64url}`. */
    public const KEY = '/^(i\\d+|d\\d{6}-[A-Za-z0-9_-]+)$/';

    /** Порядок внутри месяца — сначала где нужен наш ход. */
    private const ORDER = ['drift' => 0, 'send' => 1, 'blocked' => 2, 'nobody' => 3, 'overdue' => 4, 'due' => 5, 'paid' => 6];

    /**
     * @param  Collection<int, array>  $rows  строки живого документа (`Closing::items` одной группы)
     * @param  ?array{was: float, now: float}  $drift
     */
    private function __construct(
        public readonly Carbon $month,
        public readonly string $key,
        public readonly ?string $group,
        public readonly ?Invoice $invoice,
        public readonly Collection $rows,
        public readonly ?Party $party,
        public readonly ?Vendor $vendor,
        public readonly string $name,
        public readonly string $what,
        public readonly int $cars,
        public readonly float $amount,
        public readonly string $state,
        public readonly ?array $drift = null,
        public readonly bool $extra = false,
    ) {}

    /**
     * Документы последних `$months` месяцев и любого более раннего с живым или отправленным документом — по месяцам,
     * новые сверху (05.11.2026, владелец: «за всю историю счета и акты составлять»).
     *
     * @return Collection<string, Collection<int, self>> «Y-m» → документы по порядку дела
     */
    public static function window(int $months = 6): Collection
    {
        $now = now()->startOfMonth();
        $vehicles = Closing::vehicles(now()->startOfDay());
        // Самый ранний невыставленный день — отсюда начинаются живые месяцы (помесячная ТС с истории — в своём).
        $earliest = $vehicles->map(fn ($v) => $v->storage_billed_until?->copy()->addDay() ?? $v->accepted_at)->filter()->min();
        $from = $now->copy()->subMonths($months - 1);
        $from = $earliest && $earliest->lt($from) ? Carbon::instance($earliest)->startOfMonth() : $from;
        // И отправленные раньше самого раннего живого (прошлое отмечают по месяцу — август 2024 отправлен, живой уже сентябрь):
        // список — вся история документов, а не окно.
        $sentFrom = Invoice::where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->ofPark()->whereNotNull('month')->min('month');
        $from = $sentFrom && Carbon::parse($sentFrom)->lt($from) ? Carbon::parse($sentFrom)->startOfMonth() : $from;
        $list = collect();
        for ($m = $now->copy(); $m->gte($from); $m->subMonth()) {
            $list->push($m->copy());
        }
        $sent = self::sent($list);
        $out = collect();
        foreach ($list as $month) {
            $mine = $sent->get($month->format('Y-m'), collect());
            $sentGroups = $mine->map(fn ($i) => Closing::groupOfInvoice($i))->filter()->all();
            $docs = Closing::items($month, vehicles: $vehicles)->groupBy(fn ($i) => Closing::groupOf($i))
                ->map(fn ($rows, $group) => self::ofRows($month, (string) $group, $rows, in_array($group, $sentGroups, true)))->values()
                ->concat($mine->map(fn ($i) => self::issued($month, $i)));
            if ($docs->isNotEmpty()) {
                $out->put($month->format('Y-m'), $docs->sortBy(fn (self $d) => [self::ORDER[$d->state], -$d->amount])->values());
            }
        }

        return $out;
    }

    /** Живой документ по ключу строки (карточка рядом): месяц и группа — в самом ключе. */
    public static function find(string $key): ?self
    {
        if (preg_match('/^i(\d+)$/', $key, $m)) {
            $invoice = Invoice::ofPark()->where('direction', 'issued')->with(['party', 'charges.vehicle.vendor', 'payments'])->find($m[1]);

            return $invoice ? self::issued(self::monthOf($invoice), $invoice) : null;
        }
        if (! $live = self::parseLive($key)) {
            return null;
        }
        [$month, $group] = $live;
        $rows = Closing::rowsOf($month, $group);

        return $rows->isEmpty() ? null : self::ofRows($month, $group, $rows, false);
    }

    /** @return ?array{0: Carbon, 1: string} месяц и группа живого документа из ключа строки */
    public static function parseLive(string $key): ?array
    {
        if (! preg_match('/^d(\d{4})(\d{2})-([A-Za-z0-9_-]+)$/', $key, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }
        $group = base64_decode(strtr($m[3], '-_', '+/'), true);

        return $group === false || $group === '' ? null : [Carbon::create((int) $m[1], (int) $m[2], 1)->startOfMonth(), $group];
    }

    /** Ключ строки живого документа — для карточки рядом (`Detail`) и ссылок из уведомлений. */
    public static function liveKey(Carbon $month, string $group): string
    {
        return 'd'.$month->format('Ym').'-'.rtrim(strtr(base64_encode($group), '+/', '-_'), '=');
    }

    private static function ofRows(Carbon $month, string $group, Collection $rows, bool $extra): self
    {
        $first = $rows->first();
        $party = $first['party'];
        $vehicle = $first['vehicle'];
        // Плательщик-вендор — именем вендора: у Альфы Москвы и СПб одно юрлицо, строки иначе не различить.
        $vendor = $first['payer'] === 'vendor' ? $vehicle->vendor : ($party?->id ? Vendor::ofParty($party->id) : null);
        $name = $first['payer'] === 'vendor' ? (string) $vehicle->vendor?->name : ($party?->name ?? 'Без плательщика');
        $what = match (true) {
            $first['tow'] => 'эвакуация',
            $first['payer'] === 'buyer' => 'покупатель, '.$vehicle->titleWithYear(),
            $first['payer'] === 'owner' => 'страхователь',
            default => 'хранение',
        };
        $state = match (true) {
            ! $party => 'nobody',
            ! $first['ready'] => 'blocked',
            default => 'send',
        };

        return new self($month, self::liveKey($month, $group), $group, null, $rows, $party, $vendor, $name, $what,
            $rows->pluck('vehicle.id')->unique()->count(), round((float) $rows->sum('amount'), 2), $state, extra: $extra);
    }

    private static function issued(Carbon $month, Invoice $invoice): self
    {
        $cars = $invoice->registryVehicles();
        $own = $cars->first(fn ($v) => $v->vendor && $v->vendor->party_id === $invoice->party_id)?->vendor;
        $vendor = $own ?? Vendor::ofParty($invoice->party_id);
        $drift = Closing::drift($invoice);
        $state = match (true) {
            (bool) $drift => 'drift',
            $invoice->state === InvoiceState::Paid => 'paid',
            $invoice->isOverdue() => 'overdue',
            default => 'due',
        };
        $what = match (true) {
            $invoice->kind === ChargeKind::Tow => 'эвакуация',
            ! $invoice->isRegistry() && $invoice->vehicle => 'покупатель, '.$invoice->vehicle->titleWithYear(),
            default => 'хранение',
        };

        return new self($month, 'i'.$invoice->id, Closing::groupOfInvoice($invoice), $invoice, collect(), $invoice->party, $vendor,
            $own?->name ?? $invoice->party->name, $what, max(1, $cars->count()), (float) $invoice->total, $state, $drift);
    }

    /**
     * Отправленные документы парковки за эти месяцы: реестры — по месяцу, счёт покупателю на ТС — по концу его хранения.
     *
     * @param  Collection<int, Carbon>  $months
     * @return Collection<string, Collection<int, Invoice>>
     */
    private static function sent(Collection $months): Collection
    {
        $from = $months->min()->toDateString();
        $to = $months->max()->copy()->endOfMonth()->toDateString();

        return Invoice::where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->ofPark()
            ->where(fn ($q) => $q->whereBetween('month', [$from, $to])
                ->orWhere(fn ($b) => $b->whereNull('month')->whereNotNull('vehicle_id')->whereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Storage)->whereBetween('period_to', [$from, $to]))))
            ->with(['party', 'vehicle', 'charges.vehicle.vendor', 'payments'])->latest('id')->get()
            ->groupBy(fn (Invoice $i) => self::monthOf($i)->format('Y-m'));
    }

    private static function monthOf(Invoice $invoice): Carbon
    {
        $day = $invoice->month ?? $invoice->charges->where('kind', ChargeKind::Storage)->max('period_to') ?? $invoice->issued_at;

        return Carbon::parse($day)->startOfMonth();
    }

    public function live(): bool
    {
        return $this->invoice === null;
    }

    /** Состояние словом справа в строке и у названия в карточке. */
    public function word(): string
    {
        return match ($this->state) {
            'drift' => 'изменилось',
            'send' => 'отправить',
            'blocked' => 'нет реквизитов',
            'nobody' => 'нет плательщика',
            'paid' => 'оплачен',
            'overdue' => $this->invoice->overdueLabel(),
            default => $this->invoice->due_at ? 'до '.Ago::day($this->invoice->due_at) : 'отправлен',
        };
    }

    public function tone(): string
    {
        return match ($this->state) {
            'send' => 'text-urgent',
            'drift', 'blocked', 'nobody', 'overdue' => 'text-danger',
            'paid' => 'text-accent-text',
            default => $this->invoice?->light() === 'urgent' ? 'text-urgent' : 'text-ink-muted',
        };
    }

    /**
     * Файлы документа для шторки документов (`x-ui.doc`): счёт PDF и акт — прежняя форма страховой (Excel, Word; у РГС —
     * акт и приложение), у счёта покупателю — PDF акта хранения. `$draft` — живой счёт с номером, который он получит.
     *
     * @return list<array{url: string, type: string, name: string, label: string, ext: string}>
     */
    public function files(?Invoice $draft = null): array
    {
        // Без реквизитов и без плательщика файла не собрать: счёт без сторон никому не нужен.
        if ($this->state === 'nobody' || $this->state === 'blocked') {
            return [];
        }
        $no = ($this->invoice ?? $draft)?->fileNo();
        $named = fn (string $title, string $ext) => $title.($no ? ' '.$no : '').'.'.$ext;
        $type = fn (string $ext) => match ($ext) {
            'xlsx' => 'sheet', 'docx' => 'word', default => 'pdf'
        };
        if ($i = $this->invoice) {
            $base = '/money/invoices/'.$i->id;
            $out = [['url' => $base.'/pdf', 'type' => 'pdf', 'name' => $named('Счет', 'pdf'), 'label' => 'Счёт', 'ext' => 'pdf']];
            foreach ($i->getMedia('act')->values() as $n => $m) {
                $ext = pathinfo($m->file_name, PATHINFO_EXTENSION);
                $out[] = ['url' => $base.'/act'.($n ? '?n='.$n : ''), 'type' => $type($ext), 'name' => $m->file_name, 'label' => self::actLabel(pathinfo($m->file_name, PATHINFO_FILENAME)), 'ext' => $ext];
            }

            return $out;
        }
        $base = '/money/draft/'.$this->key;
        $out = [['url' => $base.'/pdf', 'type' => 'pdf', 'name' => $named('Счет', 'pdf'), 'label' => 'Счёт', 'ext' => 'pdf']];
        $first = $this->rows->first();
        if (! $first['tow'] && $first['payer'] === 'buyer') {
            return [...$out, ['url' => $base.'/act', 'type' => 'pdf', 'name' => $named('Акт хранения', 'pdf'), 'label' => 'Акт', 'ext' => 'pdf']];
        }
        $form = $first['vehicle']->vendor?->act_form ?? ActForm::Report;
        $n = 0;
        foreach ($form->files($first['tow']) as $template => $title) {
            $ext = pathinfo($template, PATHINFO_EXTENSION);
            $out[] = ['url' => $base.'/act'.($n ? '?n='.$n : ''), 'type' => $type($ext), 'name' => $named($title, $ext), 'label' => self::actLabel($title), 'ext' => $ext];
            $n++;
        }

        return $out;
    }

    /** Подпись файла в чипе — коротко: «Акт», «Приложение». */
    private static function actLabel(string $title): string
    {
        return str_starts_with(mb_strtolower($title), 'приложение') ? 'Приложение' : 'Акт';
    }

    /**
     * Состав документа по смыслу, а не списком машин (владелец: «огромный список, странный, бесцельный»): по ТС — период,
     * сутки и сумма; группы по движению в месяце (помесячно: стояли весь месяц, приняты, выданы; по выдаче — все выданы).
     * По типу ТС не делится (владелец: «не вижу смысла»).
     *
     * @param  Collection<int, Charge>  $charges  строки документа (живого или отправленного)
     * @return array{cars: Collection<int, array>, moves: list<array{label: string, cars: int, amount: float}>, other: float}
     */
    public static function composition(Collection $charges, Carbon $month): array
    {
        $begin = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $cars = $charges->filter(fn ($c) => $c->vehicle_id)->groupBy('vehicle_id')->map(function ($rows) {
            $storage = $rows->where('kind', ChargeKind::Storage);

            return ['vehicle' => $rows->first()->vehicle, 'from' => $storage->min('period_from'), 'to' => $storage->max('period_to'),
                'days' => (int) $storage->sum('qty'), 'rates' => $storage->pluck('price')->map(fn ($p) => (float) $p)->unique()->values()->all(),
                'amount' => round((float) $rows->sum('amount'), 2), 'extra' => $rows->where('kind', '!=', ChargeKind::Storage)->pluck('title')->all()];
        })->sortByDesc('amount')->values();
        $stored = $cars->filter(fn ($c) => $c['from']);
        $moves = [];
        $released = $stored->filter(fn ($c) => $c['vehicle']->released_at && $c['vehicle']->released_at->between($begin, $end->copy()->endOfDay()));
        $accepted = $stored->diffKeys($released)->filter(fn ($c) => $c['vehicle']->accepted_at && $c['vehicle']->accepted_at->gte($begin));
        $whole = $stored->diffKeys($released)->diffKeys($accepted);
        $in = mb_strtolower($month->translatedFormat('F'));
        // По выдаче все строки — выданные в месяце: делить нечего, движение не рисуется.
        if ($whole->isNotEmpty() || $accepted->isNotEmpty()) {
            foreach ([['Стояли весь месяц', $whole], ['Приняты в '.self::prepositional($in), $accepted], ['Выданы в '.self::prepositional($in), $released]] as [$label, $set]) {
                if ($set->isNotEmpty()) {
                    $moves[] = ['label' => $label, 'cars' => $set->count(), 'amount' => round((float) $set->sum('amount'), 2)];
                }
            }
        }

        return ['cars' => $cars, 'moves' => $moves, 'other' => round((float) $charges->where('kind', '!=', ChargeKind::Storage)->sum('amount'), 2)];
    }

    /**
     * История отправленного документа строками: прежние редакции того же номера, как ушёл этот (письмом — кому, с
     * письмом в шторке документов; сами — кто отметил), оплаты.
     *
     * @return list<array{at: \DateTimeInterface, text: string, doc: ?array}>
     */
    public static function history(Invoice $invoice): array
    {
        $out = [];
        if ($invoice->code && $invoice->revision) {
            $earlier = Invoice::ofPark()->where('seller', $invoice->seller)->where('code', $invoice->code)->where('revision', '<', $invoice->revision)->orderBy('revision')->get();
            foreach ($earlier as $e) {
                $out[] = ['at' => $e->sent_at ?? $e->created_at, 'text' => ($e->revision ? 'Ред. '.$e->revision : 'Первая редакция').' на '.Money::rub($e->total).', заменена', 'doc' => null];
            }
        }
        if ($invoice->sent_at) {
            $m = $invoice->sentMessage;
            $to = $m?->addressesOfKind(AddressKind::To)->pluck('email')->filter()->implode(', ');
            $out[] = ['at' => $invoice->sent_at, 'text' => match (true) {
                (bool) $m => 'Отправлен письмом'.($to ? ' на '.$to : ''),
                (bool) $invoice->sender => 'Отмечен отправленным, '.$invoice->sender->shortName(),
                default => 'Отправлен',
            }, 'doc' => $m ? Docs::letter($m, '/mail') : null];
        }
        foreach ($invoice->payments->sortBy('paid_at') as $p) {
            $out[] = ['at' => $p->paid_at, 'text' => 'Оплачено '.Money::rub($p->amount).($p->ref ? ', № '.$p->ref : ''), 'doc' => null];
        }

        return $out;
    }

    /** «в сентябре», «в мае»: месяц в предложном падеже. */
    private static function prepositional(string $month): string
    {
        return match (true) {
            str_ends_with($month, 'ь'), str_ends_with($month, 'й') => mb_substr($month, 0, -1).'е',
            default => $month.'е',
        };
    }
}
