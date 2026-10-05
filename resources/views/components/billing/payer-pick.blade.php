{{-- Кто платит по ссылке — строками с аватарами: я, мои покупатели, новый покупатель по ФИО (заводится в его
     «Покупателях», `PayChoice`). Почта для чека — только у того, у кого её нет: чек ЮKassa приходит на почту. Одно на
     шторку «Оплатить» и смену плательщика в расчёте сделки. --}}
@props(['id' => 'pay', 'buyers' => collect(), 'payer' => 'self', 'otherName' => null, 'cap' => true])
@php
    $me = auth()->user();
    $picked = fn ($b) => $payer === (string) $b->id || ($payer === 'buyer' && (int) old('payer_user_id') === $b->id);
@endphp
<div data-controller="reveal">
    @if ($cap)<div class="list-cap">Кто платит</div>@endif
    <div class="list max-h-72 overflow-y-auto">
        <label class="row row-check">
            <x-ui.avatar :user="$me" :size="36"/>
            <span class="min-w-0 flex-1"><span class="block truncate">Я</span>@if ($me->email)<span class="row-sub">{{ $me->email }}</span>@endif</span>
            <span class="check"><input type="radio" name="payer" value="self" @checked($payer === 'self') data-action="reveal#pick"></span>
        </label>
        @foreach ($buyers as $b)
            <label class="row row-check">
                <x-ui.avatar :user="$b" :size="36"/>
                <span class="min-w-0 flex-1"><span class="block truncate">{{ $b->name }}</span>@if ($b->email)<span class="row-sub">{{ $b->email }}</span>@endif</span>
                <span class="check"><input type="radio" name="payer" value="{{ $b->id }}" @checked($picked($b)) data-action="reveal#pick"></span>
            </label>
        @endforeach
        <label class="row row-check">
            <x-ui.row-icon name="plus" size="s"/>
            <span class="min-w-0 flex-1">Новый покупатель</span>
            <span class="check"><input type="radio" name="payer" value="other" @checked($payer === 'other') data-action="reveal#pick"></span>
        </label>
    </div>
    @error('payer_user_id')<div class="mt-2 text-sm text-danger">{{ $message }}</div>@enderror
    @unless ($me->email)
        <div class="mt-3" data-reveal-target="pane" data-reveal-key="self" @if ($payer !== 'self') hidden @endif><x-ui.field name="email" id="{{ $id }}-email-self" label="Почта для чека" type="email"/></div>
    @endunless
    @foreach ($buyers->reject(fn ($b) => $b->email) as $b)
        <div class="mt-3" data-reveal-target="pane" data-reveal-key="{{ $b->id }}" @unless ($picked($b)) hidden @endunless><x-ui.field name="email" id="{{ $id }}-email-{{ $b->id }}" label="Почта для чека" type="email"/></div>
    @endforeach
    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" data-reveal-target="pane" data-reveal-key="other" @if ($payer !== 'other') hidden @endif>
        <x-ui.field name="name" id="{{ $id }}-name" label="ФИО" :value="$otherName"/>
        <x-ui.field name="email" id="{{ $id }}-email-other" label="Почта для чека" type="email"/>
    </div>
</div>
