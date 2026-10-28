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
                <x-people.option :picker="false" :person="['id' => 'me'] + ($mine ? ['name' => 'Я сам'] : []) + \App\Support\People::row($manager, $mine ? '' : 'менеджер')" name="who" :checked="$who === 'me'" action="reveal#pick"/>
            @endif
            @foreach ($buyers as $b)
                <x-people.option :picker="false" :person="\App\Support\People::row($b, $b->phone ? \App\Support\Phone::format($b->phone) : '')" name="who" :checked="$who === (string) $b->id" action="reveal#pick"/>
            @endforeach
            <label class="row row-check">
                <x-ui.row-icon name="plus" size="s" tone="accent"/>
                <span class="min-w-0 flex-1 font-medium text-accent-text">Другой человек</span>
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
