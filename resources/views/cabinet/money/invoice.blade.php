{{-- Счёт менеджеру: строки (тут он и видит агентское вознаграждение), оплаты и заявки, PDF; главное действие —
     «Сообщить об оплате» с платёжкой. Справа — ТС и плательщик. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentState; use App\Billing\PaymentSource; $i = $invoice; $offer = $i->deal?->offer; @endphp
<x-ui.cabinet :title="'Счёт '.$i->label()" :back="['Деньги', '/account/money']">
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="min-w-0 space-y-6">
            <div class="box">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h2 class="text-xl">Счёт {{ $i->label() }}</h2>
                    <x-billing.light :invoice="$i"/>
                    <span class="tag nums">от {{ $i->issued_at->translatedFormat('j M Y') }}</span>
                </div>
                <div class="mt-4 flex flex-col divide-y divide-line/40">
                    @foreach ($i->charges as $c)
                        <div class="flex items-baseline gap-3 py-2"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums shrink-0 font-medium">{{ Money::rub($c->amount) }}</span></div>
                    @endforeach
                    <div class="flex items-baseline gap-3 py-2"><span class="flex-1 font-medium">Итого</span><span class="nums text-lg font-semibold">{{ Money::rub($i->total) }}</span></div>
                    @if ($i->vat)<div class="text-sm text-ink-muted">в том числе НДС {{ Money::rub($i->vatAmount()) }}</div>@endif
                </div>
                @if ($i->allPayments->isNotEmpty())
                    <div class="mt-4 flex flex-col divide-y divide-line/40 border-t border-line/40">
                        @foreach ($i->allPayments as $p)
                            @php [$title, $cls] = match (true) {
                                $p->state === PaymentState::Claimed => ['Сообщили об оплате, ждёт подтверждения', 'text-urgent'],
                                $p->state === PaymentState::Rejected => ['Не поступила'.($p->reject_reason ? ': '.$p->reject_reason : ''), 'text-ink-muted line-through'],
                                $p->source === PaymentSource::Offset => ['Удержано агентское вознаграждение', ''],
                                default => ['Оплата принята', ''],
                            }; @endphp
                            <div class="flex items-center gap-3 py-2 {{ $p->state === PaymentState::Rejected ? 'text-ink-muted' : '' }}">
                                <span class="min-w-0 flex-1">{{ $title }}@if ($p->slip()) <a href="/account/money/invoices/{{ $i->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span>
                                <span class="nums text-sm text-ink-muted">{{ $p->paid_at->translatedFormat('j M') }}</span>
                                <span class="nums font-medium {{ $cls }}">{{ Money::rub($p->amount) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($i->state === InvoiceState::Issued && $i->remaining() > 0)
                    <div class="mt-4 flex items-baseline justify-between gap-3"><span>Остаток</span><span class="nums text-lg font-semibold">{{ Money::rub($i->remaining()) }}</span></div>
                @endif
                @if ($file)<x-ui.button href="/account/invoices/{{ $i->id }}/pdf" variant="secondary" size="s" class="mt-4" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-4"/> PDF счёта</x-ui.button>@endif
            </div>
        </div>
        <aside class="lg:self-start space-y-4">
            @if ($offer)
                <div class="box overflow-hidden !p-0">
                    <a href="/offers/{{ $offer->number }}" class="group block">
                        @if ($photo = $offer->mainPhoto())<x-offer.photo :media="$photo" sizes="(min-width: 1024px) 320px, 100vw" class="aspect-[4/3] w-full object-cover"/>@endif
                        <div class="p-5"><p class="font-medium group-hover:text-accent-text">{{ $offer->titleWithYear() }}</p><p class="row-sub mt-1.5"><span class="tag nums">№ {{ $offer->number }}</span></p></div>
                    </a>
                </div>
            @endif
            <div class="box">
                <p class="text-sm text-ink-dim">Плательщик</p>
                <p class="mt-0.5 font-medium">{{ $i->party->name }}</p>
                @if ($i->party->details())<p class="mt-1 text-sm text-ink-muted">{{ $i->party->details() }}</p>@endif
            </div>
        </aside>
    </div>
    @if ($i->state === InvoiceState::Issued && $left > 0)
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Сообщить об оплате</x-ui.button></x-ui.action-bar>
            <x-ui.sheet id="claim" title="Сообщить об оплате" :open="$errors->any()">
                <form method="post" action="/account/money/invoices/{{ $i->id }}/claims" enctype="multipart/form-data" class="flex flex-col gap-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="amount" label="Сумма, ₽" :value="rtrim(rtrim(number_format($left, 2, '.', ''), '0'), '.')" inputmode="decimal" required/>
                        <x-ui.field name="paid_at" label="Дата оплаты" type="date" :value="now()->toDateString()" required/>
                        <x-ui.field name="ref" label="№ платёжки"/>
                        <x-ui.field name="slip" label="Платёжное поручение" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic" required/>
                    </div>
                    <x-ui.button block>Отправить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.cabinet>
