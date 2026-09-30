{{-- Открытая ссылка на оплату строкой денег: кружок QR, кто платит, сумма оранжевым — ждём. Нажатие — шторка, как чек
     перевода: QR для оплаты с другого телефона, сумма крупно, «Скопировать» и «Отправить», внизу серым «Отменить ссылку».
     Свежесозданная открывается сама. --}}
@props(['link', 'cancel'])
@php use App\Support\Money; $who = $link->payerLabel(auth()->user()); @endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row money-line w-full text-left" data-action="sheet#open">
        <x-ui.row-icon name="qr" tone="urgent" size="s"/>
        <span class="min-w-0 flex-1">
            <span class="block truncate">Ссылка на оплату</span>
            <span class="row-sub">{{ $who === 'вы' ? 'платите вы' : 'платит '.$who }}, ждём оплату</span>
        </span>
        <span class="nums shrink-0 text-urgent">{{ Money::rub($link->amount) }}</span>
    </button>
    <x-ui.sheet :id="'link-'.$link->id" title="Ссылка на оплату" :open="session('open-link') === $link->id">
        <div class="flex flex-col gap-4">
            <div class="money-hero">
                <span class="nums text-[32px] font-semibold leading-tight">{{ Money::rub($link->amount) }}</span>
                <span class="text-ink-muted">{{ $who === 'вы' ? 'платите вы' : 'платит '.$who }}@if ($link->payer_email), чек на {{ $link->payer_email }}@endif</span>
            </div>
            <div class="mx-auto w-52 rounded-(--radius-l) bg-white p-3 text-black">{!! \App\Support\Qr::svg($link->url()) !!}</div>
            <x-ui.copy-link :url="$link->url()" :title="'Оплата по счёту '.$link->invoice->label()"/>
            <form method="post" action="{{ $cancel }}" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">
                @csrf @method('delete')
                <x-ui.button variant="ghost" block class="text-ink-muted">Отменить ссылку</x-ui.button>
            </form>
        </div>
    </x-ui.sheet>
</div>
