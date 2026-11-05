{{-- Где нужен наш ход — плашкой над таблицей документа, только когда есть: изменилось после отправки (исправленный — тем же
     номером), нет реквизитов (шторка реквизитов здесь же), не вошли. --}}
@php
    use App\Support\Money;
    $d = $doc; $i = $invoice; $manage = auth()->user()->canManagePark();
    $closingKey = $d->month->format('Y-m').'|'.$d->group;
    $reviseMail = $d->drift && $i->paid == 0 && $d->group ? '/mail/new?'.http_build_query(['closing' => $closingKey, 'replaces' => $i->id]) : null;
@endphp
@if ($d->drift)
    <div class="doc-alert">
        <div>Изменилось после отправки: было <span class="nums">{{ Money::rub($d->drift['was']) }}</span>, сейчас <span class="nums">{{ Money::rub($d->drift['now']) }}</span>@if ($i->paid > 0). По счёту уже платили — исправленный не отправить@endif</div>
        @if ($reviseMail && $manage)
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="btn btn-s btn-accent" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $reviseMail }}"><x-ui.icon name="send" class="size-4"/>Отправить исправленный</button>
                <span data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open">Отправили сами</button>
                    <x-ui.sheet :id="'fix-r-'.$d->key" title="Исправленный отправили сами">@include('park.money.closing-fix', ['replaces' => $i])</x-ui.sheet>
                </span>
            </div>
        @endif
    </div>
@endif
@if ($d->state === 'blocked')
    <div class="doc-alert flex flex-wrap items-center gap-3" data-controller="sheet">
        <span class="min-w-0 flex-1">Нет реквизитов {{ $d->party?->name }} — без них счёт не собрать</span>
        @if ($manage)
            <button type="button" class="btn btn-s btn-accent" data-action="sheet#open">Заполнить реквизиты</button>
            <x-ui.sheet id="party-{{ $d->key }}" :title="(string) $d->party?->name" wide tall>@include('park.money.party-form', ['party' => $d->party, 'vendor' => $d->rows->first()['payer'] === 'vendor' ? $d->rows->first()['vehicle']->vendor : null])</x-ui.sheet>
        @endif
    </div>
@endif
@if ($skipped)
    <div class="doc-alert">Не вошли: {{ implode('; ', $skipped) }}</div>
@endif
