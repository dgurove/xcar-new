{{-- Заметка по сделке — текстом, «Изменить / Готово» ставит поле на его место (07.10.2026, владелец: «слишком много
     полей»; `edit_card_controller`). Пустая — одна кнопка «Добавить» в заголовке. В карточке строки «Сделок» и в дорожке
     «Сделка» редактора. --}}
@props(['deal'])
@php $form = 'deal-note-'.$deal->id; @endphp
<x-ui.card title="Заметка" {{ $attributes->class(['deal-note']) }} data-controller="edit-card" :data-edit-card-form-value="$form">
    <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle" @unless ($deal->notes) data-idle="Добавить" @endunless>{{ $deal->notes ? 'Изменить' : 'Добавить' }}</button></x-slot:actions>
    <div data-edit-card-target="view">@if ($deal->notes)<p class="whitespace-pre-line break-words">{{ $deal->notes }}</p>@endif</div>
    <div data-edit-card-target="edit" hidden>
        <form method="post" action="/work/deals/{{ $deal->id }}/note" id="{{ $form }}" class="flex flex-col gap-2" data-controller="save-bar">
            @csrf
            <textarea name="notes" class="field-input" placeholder="Что важно помнить по этой сделке">{{ old('notes', $deal->notes) }}</textarea>
            <x-ui.save-bar/>
        </form>
    </div>
</x-ui.card>
