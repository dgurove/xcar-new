{{-- Кто заберёт машину с нашей парковки (18.10.2026, владелец: «решает менеджер в сделке») — строками с аватарами, как
     «Кто платит»: менеджер сделки, его покупатели, другой человек по ФИО и телефону; ниже «Когда приедете». Одно на
     страницу сделки менеджера и карточку сделки CRM (админ меняет за менеджера). Сохранили — пропуск новый, парковка видит. --}}
@props(['deal', 'action', 'id' => 'receiver', 'receiver' => null])
@php
    $manager = $deal->buyer;
    $mine = auth()->id() === $deal->buyer_id;
    $buyers = $manager?->buyers()->with(\App\Users\User::withAvatar())->orderBy('name')->get() ?? collect();
    $r = $receiver ?? $deal->receiver();
    $who = old('who', match (true) {
        filled($deal->receiver_name) => 'other',
        $r?->user && $r->user->id !== $deal->buyer_id && $buyers->contains('id', $r->user->id) => (string) $r->user->id,
        default => 'me',
    });
@endphp
<div data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open" class="contents">
<x-ui.sheet :id="$id" title="Кто заберёт">
    <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="reveal">
        @csrf @method('PUT')
        <div class="list max-h-72 overflow-y-auto">
            @if ($manager)
                <label class="row row-check">
                    <x-ui.avatar :user="$manager" :size="36"/>
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $mine ? 'Я сам' : $manager->name }}</span>@unless ($mine)<span class="row-sub">менеджер</span>@endunless</span>
                    <span class="check"><input type="radio" name="who" value="me" @checked($who === 'me') data-action="reveal#pick"></span>
                </label>
            @endif
            @foreach ($buyers as $b)
                <label class="row row-check">
                    <x-ui.avatar :user="$b" :size="36"/>
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $b->name }}</span>@if ($b->phone)<span class="row-sub nums">{{ \App\Support\Phone::format($b->phone) }}</span>@endif</span>
                    <span class="check"><input type="radio" name="who" value="{{ $b->id }}" @checked($who === (string) $b->id) data-action="reveal#pick"></span>
                </label>
            @endforeach
            <label class="row row-check">
                <x-ui.row-icon name="plus" size="s"/>
                <span class="min-w-0 flex-1">Другой человек</span>
                <span class="check"><input type="radio" name="who" value="other" @checked($who === 'other') data-action="reveal#pick"></span>
            </label>
        </div>
        @error('who')<div class="text-sm text-danger">{{ $message }}</div>@enderror
        <div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($who !== 'other') hidden @endif>
            <x-ui.field name="name" id="{{ $id }}-name" label="ФИО" :value="old('name', $deal->receiver_name)"/>
            <x-ui.field name="phone" id="{{ $id }}-phone" label="Телефон" type="tel" :value="old('phone', $deal->receiver_phone)"/>
        </div>
        <div class="fields">
            <x-ui.field name="at" id="{{ $id }}-at" label="Когда приедет" type="datetime-local" default-time="12:00"
                :value="old('at', $deal->receiver_at?->timezone('Europe/Moscow')->format('Y-m-d\TH:i'))"/>
        </div>
        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
</div>
