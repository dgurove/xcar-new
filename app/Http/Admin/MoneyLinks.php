<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Invoice;
use App\Offers\OfferNumber;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\Sort;
use App\Users\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * «По ссылкам» — все ссылки на оплату одним списком (09.10.2026, владелец: «один список из ссылок с сортировкой по дате
 * создания, у всех подписана марка, модель и номер, и подписано, какие созданы сделкой, а какие вручную»): строка —
 * ссылка, а не счёт. Сделки, гараж, взятые менеджером в кабинете и кнопкой «Создать ссылку» — все здесь.
 * Отменённые (заменённые другой суммой, погашенные) — только чипом «Отменённые».
 */
final class MoneyLinks
{
    /** Откуда ссылка: разовая (кнопкой в «Оплатах»), счёт сделки, машина гаража. */
    public const FROM = "(case when billing_invoices.kind = 'service' and billing_invoices.vehicle_id is null then 'manual' when billing_invoices.deal_id is not null then 'deal' else 'garage' end)";

    public const FROM_LABELS = ['deal' => 'Сделка', 'garage' => 'Гараж', 'manual' => 'Вручную'];

    public const WITH = ['invoice.party', 'invoice.charges', 'invoice.offer.brand', 'invoice.offer.model', 'invoice.deal.offer.brand', 'invoice.deal.offer.model', 'invoice.deal.buyer',
        'invoice.garageCar.offer.brand', 'invoice.garageCar.offer.model', 'invoice.garageCar.manager', 'invoice.creditDeal.offer', 'invoice.creditDeal.buyer', 'invoice.creator', 'attempts', 'creator', 'payerUser'];

    public static function sorts(): array
    {
        return ['created' => ['Создано', 'desc'], 'payer' => ['Плательщик', 'asc'], 'amount' => ['Сумма', 'desc']];
    }

    public static function facets(Request $request): Facets
    {
        return Facets::for($request, 'crm-money-links',
            Facet::column('from', 'Откуда', ['источник', 'источника', 'источников'], self::FROM)
                ->labels(fn (array $keys) => collect(self::FROM_LABELS)->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all())->natural(),
            Facet::toggle('canceled', 'Отменённые', fn ($q) => $q),
        );
    }

    /** Ссылки счетов раздела (`Invoice::crmMoney` — его области демо и города), с машиной и плательщиком. */
    public static function query(): Builder
    {
        return PayLink::query()->select('billing_pay_links.*')
            ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_pay_links.invoice_id')
            ->whereIn('billing_pay_links.invoice_id', Invoice::crmMoney()->where('direction', 'issued')->select('billing_invoices.id'));
    }

    /** Отменённые — только с чипом; число пилюли — открытые. */
    public static function base(Facets $facets): Builder
    {
        return self::query()->when(! $facets->on('canceled'), fn ($q) => $q->where('billing_pay_links.state', '!=', PayLinkState::Canceled->value));
    }

    public static function list(Facets $facets, ?Closure $find, Sort $sort, bool $paged)
    {
        $q = self::base($facets);
        $facets->apply($q);
        $q->when($find, $find)->with(self::WITH);
        $dir = $sort->dir();
        match ($sort->key) {
            'payer' => $q->orderByRaw("coalesce(nullif(billing_pay_links.payer_name, ''), (select name from billing_parties where billing_parties.id = billing_invoices.party_id)) {$dir}"),
            'amount' => $q->orderBy('billing_pay_links.amount', $dir),
            default => $q->orderBy('billing_pay_links.created_at', $dir),
        };
        $q->orderBy('billing_pay_links.id', $dir);

        return $paged ? $q->paginate(50)->withQueryString() : $q->limit(50)->get();
    }

    public static function openCount(): int
    {
        return self::query()->where('billing_pay_links.state', PayLinkState::Open->value)->count();
    }

    /** По открытой ссылке пытались и не вышло — пилюля оранжевая. */
    public static function tried(): bool
    {
        return self::query()->where('billing_pay_links.state', PayLinkState::Open->value)
            ->where(fn ($w) => $w->whereNotNull('billing_pay_links.error_at')->orWhereHas('attempts', fn ($a) => $a->where('status', 'canceled')))->exists();
    }

    /** Лупа: плательщик (двойные пробелы не мешают), ТС словами, марка, модель, номер предложения, код ссылки. */
    public static function find(string $qs): ?Closure
    {
        if ($qs === '') {
            return null;
        }
        $like = '%'.str_replace(' ', '%', PayLink::squash($qs)).'%';
        $offer = fn ($o) => $o->where(fn ($w) => $w->when(ctype_digit($qs), fn ($x) => $x->orWhere('number', (int) $qs))
            ->orWhereHas('brand', fn ($b) => $b->where('name', 'ilike', $like))->orWhereHas('model', fn ($m) => $m->where('name', 'ilike', $like)));

        return fn ($q) => $q->where(fn ($s) => $s
            ->where('billing_pay_links.payer_name', 'ilike', $like)
            ->orWhere('billing_pay_links.code', mb_strtolower($qs))
            ->orWhere('billing_invoices.car_title', 'ilike', $like)
            ->orWhereHas('invoice', fn ($i) => $i->where(fn ($w) => $w
                ->whereHas('party', fn ($p) => $p->where('name', 'ilike', $like))
                ->orWhereHas('offer', $offer)
                ->orWhereHas('deal.offer', $offer)
                ->orWhereHas('deal.buyer', fn ($u) => $u->where('name', 'ilike', $like))
                ->orWhereHas('garageCar.offer', $offer))));
    }

    /** «Сделка», «Гараж», «Вручную», у ручной, засчитанной в сделку, — «Вручную, в сделку». */
    public static function from(PayLink $l): string
    {
        $from = $l->origin();

        return $from === 'manual' && $l->invoice->credit_deal_id ? 'Вручную, в сделку' : self::FROM_LABELS[$from];
    }

    /**
     * Кто взял ссылку: у ручной — кто выставил счёт (ссылку к нему заводит система), у сделки — менеджер или сотрудник,
     * что выбрал плательщика или другую сумму; заведённая вместе со счётом сделки — «сама».
     */
    public static function creator(PayLink $l): ?User
    {
        return $l->origin() === 'manual' ? $l->invoice->creator : ($l->auto ? null : $l->creator);
    }

    /**
     * Кто взял ссылку — одним словом в «Создано» (09.10.2026, владелец: «либо имя, либо сделка; раз имя — значит
     * вручную»): у ручной — кто выставил, у сделки и гаража — откуда.
     */
    public static function who(PayLink $l): string
    {
        return match ($l->origin()) {
            'manual' => $l->invoice->creator?->shortName() ?? 'вручную',
            'deal' => 'сделка',
            default => 'гараж',
        };
    }

    /** ТС строкой без номера («Kia Rio») и номер отдельно — у таблицы свои столбцы. @return array{?string, ?int} */
    public static function car(PayLink $l): array
    {
        $o = $l->invoice->carOffer();

        return $o ? [$o->title(), OfferNumber::isPublic($o) ? $o->number : null] : [$l->invoice->car_title, null];
    }

    /**
     * Что со ссылкой одним-двумя словами — для строки списка (09.10.2026, владелец: «схалтурил»): длинная фраза
     * «ждём оплату, ещё не открывали» в каждой строке была стеной текста. Полная — `state()`, в карточке.
     * @return array{string, string}
     */
    public static function short(PayLink $l): array
    {
        if ($l->state === PayLinkState::Paid) {
            return ['оплачено', 'text-open'];
        }
        if ($l->state === PayLinkState::Canceled) {
            return ['отменена', 'text-ink-dim'];
        }
        $last = $l->relationLoaded('attempts') ? $l->attempts->last() : $l->attempts()->reorder()->latest('id')->first();

        return match (true) {
            $l->error_at && (! $last || $l->error_at->gt($last->created_at)) => ['ошибка ЮKassa', 'text-danger'],
            ! $last => ['не открывали', 'text-ink-muted'],
            $last->isPending() => ['открыли', 'text-urgent'],
            $last->status === 'canceled' && $last->cancel_reason === 'expired_on_confirmation' => ['не оплатили', 'text-urgent'],
            $last->status === 'canceled' => ['не прошла', 'text-danger'],
            default => ['ждём оплату', 'text-ink-muted'],
        };
    }

    /** День группы в списке: «Сегодня», «Вчера», «5 октября», прошлый год — с годом. */
    public static function day(\Carbon\CarbonInterface $t): string
    {
        return match (true) {
            $t->isToday() => 'Сегодня',
            $t->isYesterday() => 'Вчера',
            default => $t->translatedFormat($t->isCurrentYear() ? 'j F' : 'j F Y'),
        };
    }

    /** ТС одной строкой с номером: «Ford Kuga, № 2610061312»; нет — null. */
    public static function carLine(PayLink $l): ?string
    {
        [$car, $number] = self::car($l);

        return $car ? $car.($number ? ', № '.$number : '') : null;
    }

    /** Что со ссылкой — фраза и класс тона, как в шторке ссылки. */
    public static function state(PayLink $l): array
    {
        [$state, $tone] = $l->stateLine();

        return [$state, $l->state === PayLinkState::Canceled ? 'text-ink-dim' : ($l->state === PayLinkState::Paid ? 'text-open' : MoneyRows::tone($tone))];
    }
}
