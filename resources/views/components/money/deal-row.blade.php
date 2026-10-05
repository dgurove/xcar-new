{{-- Сделка-расчёт строкой в два этажа: фото, ТС и под ним одной строкой, что с деньгами (цветом состояния); справа число,
     под ним — что это за число. href — куда ведёт (кабинет: расчёт, CRM: сделка). person — показать менеджера (CRM).
     staff — смотрит сотрудник: подпись без «Вам» (выплата менеджеру — не ему). Фраза переносится, а не режется:
     «Укажите покупат…» на телефоне прятала то, что надо сделать. --}}
@props(['deal', 'href', 'person' => false, 'staff' => false])
@php use App\Support\Money; $offer = $deal->offer; $m = $deal->money ?? \App\Billing\DealMoney::of($deal); $caption = $staff ? preg_replace('/^Вам /u', '', (string) $m->caption) : $m->caption; @endphp
<a href="{{ $href }}" class="row">
    <span class="row-photo row-photo-s"><x-offer.photo :media="$offer->mainPhoto()" sizes="48px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub !whitespace-normal"><span class="{{ $m->phraseClass() }}">{{ $m->phrase }}</span>@if ($person && $deal->buyer)<x-ui.person :user="$deal->buyer"/>@endif</span>
    </span>
    @if ($m->amount !== null)
        <span class="shrink-0 text-right">
            <span class="nums block {{ $m->amountClass() }}">{{ Money::rub($m->amount) }}</span>
            @if ($caption)<span class="block text-xs text-ink-dim">{{ mb_strtolower($caption) }}</span>@endif
        </span>
    @endif
</a>
