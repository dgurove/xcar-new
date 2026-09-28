{{-- Открытая ссылка на оплату строкой: сумма, кто платит. Нажатие — шторка со ссылкой (скопировать, отправить),
     QR для оплаты с другого телефона и «Отменить». Свежесозданная открывается сама. --}}
@props(['link', 'cancel'])
@php use App\Support\Money; $who = $link->payerLabel(auth()->user()); @endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full justify-between text-left" data-action="sheet#open">
        <span class="min-w-0">
            <span class="block">Ссылка на оплату {{ Money::exact($link->amount) }}</span>
            <span class="row-sub">{{ $who === 'вы' ? 'платите вы' : 'платит '.$who }}, ждём оплату</span>
        </span>
        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
    </button>
    <x-ui.sheet :id="'link-'.$link->id" title="Ссылка на оплату" :open="session('open-link') === $link->id">
        <div class="flex flex-col gap-4">
            <div class="mx-auto w-48 rounded-(--radius-l) bg-white p-2 text-black">{!! \App\Support\Qr::svg($link->url()) !!}</div>
            <x-ui.copy-link :url="$link->url()" :title="'Оплата по счёту '.$link->invoice->label()"/>
            <form method="post" action="{{ $cancel }}" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">
                @csrf @method('delete')
                <x-ui.button variant="ghost" block class="text-danger">Отменить ссылку</x-ui.button>
            </form>
        </div>
    </x-ui.sheet>
</div>
