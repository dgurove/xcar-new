{{-- Действия документа в строке заголовка страницы (05.11.2026, владелец: «шапку вообще убрать: там 4 кнопки и сумма,
     номер, без НДС и сколько — необязательно; компактно, чтобы спокойно смотреть содержание»): сумма, «Отправить» и
     «Отправили сами» (у отправленного — «Оплачен» и «Отправить снова»; «Отправить» сначала спрашивает — `closing-send`), файлы счёта и акта (просмотр в шторке документов,
     скачать — в её полосе), «···». Состояние — словом у заголовка (`closing-page`). --}}
@php
    use App\Support\Money; use App\Support\Ago; use App\Billing\InvoiceState;
    $d = $doc; $i = $invoice; $manage = auth()->user()->canManagePark();
    $shown = $i ?? $draft;
    $monthWord = mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'));
    $sum = $d->drift['now'] ?? ($i ? ($i->remaining() > 0 && $i->state === InvoiceState::Issued ? $i->remaining() : $i->total) : ($draft?->total ?? $d->amount));
    $closingKey = $d->month->format('Y-m').'|'.$d->group;
    $mailUrl = $d->live() ? '/mail/new?'.http_build_query(['closing' => $closingKey]) : '/mail/new?invoice='.$i->id;
    $reviseMail = $d->drift && $i->paid == 0 && $d->group ? '/mail/new?'.http_build_query(['closing' => $closingKey, 'replaces' => $i->id]) : null;
    $issued = $i && $i->state === InvoiceState::Issued;
@endphp
<span class="doc-sum nums">{{ Money::rub($sum) }}</span>
@if ($manage && $d->state === 'send' && $d->live())
    @include('park.money.closing-send', ['label' => 'Отправить', 'class' => 'btn btn-s btn-accent'])
    <span data-controller="sheet" class="contents">
        <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="check" class="size-4"/>Отправили сами</button>
        <x-ui.sheet :id="'fix-'.$d->key" title="Отправили сами">@include('park.money.closing-fix', ['replaces' => null])</x-ui.sheet>
    </span>
@elseif ($manage && $i)
    @if ($issued)
        <span data-controller="sheet" class="contents">
            <button type="button" class="btn btn-s btn-accent" data-action="sheet#open"><x-ui.icon name="check" class="size-4"/>Оплачен</button>
            <x-ui.sheet :id="'paid-'.$i->id" :title="'Оплата по счёту '.$i->label()"><x-billing.pay-form :invoice="$i" :action="'/money/invoices/'.$i->id.'/payments'" :sources="$sources" sheet/></x-ui.sheet>
        </span>
    @endif
    @include('park.money.closing-send', ['label' => 'Отправить снова', 'class' => 'btn btn-s btn-quiet'])
@endif
@if ($files && $d->state !== 'blocked')
    <span class="contents" data-docs-scope>
        @foreach ($files as $f)
            <x-ui.doc :doc="$f" class="btn btn-s btn-quiet doc-file"><x-ui.file-icon :name="$f['name']" class="file-icon-s"/>{{ $f['label'] }}</x-ui.doc>
        @endforeach
    </span>
@endif
@include('park.money.closing-menu', ['button' => 'btn btn-s btn-quiet btn-round'])
