{{-- Один контакт владельца в `x-route.contacts`: ФИО и номер — обычные поля без подписей (подсказка внутри, текст справа),
     × — справа от группы, вне полей (17.11.2026, владелец); у первого контакта на его месте пусто, группы одной ширины.
     Подпись поля — только для экранного диктора (`labels`). --}}
@props(['id', 'i', 'name' => '', 'phone' => '', 'labels' => ['Владелец', 'Телефон владельца'], 'removable' => false])
<div class="route-contact" data-row>
    <div class="fields min-w-0 flex-1">
        <div class="field route-contact-field">
            <input id="{{ $id }}-name-{{ $i }}" name="contacts[{{ $i }}][name]" value="{{ $name }}" class="field-input" placeholder="ФИО" aria-label="{{ $labels[0] }}" autocomplete="off">
        </div>
        <div class="field route-contact-field">
            <input id="{{ $id }}-phone-{{ $i }}" name="contacts[{{ $i }}][phone]" value="{{ $phone }}" type="tel" inputmode="tel" class="field-input" placeholder="Номер телефона" aria-label="{{ $labels[1] }}" autocomplete="off">
        </div>
    </div>
    @if ($removable)
        <button type="button" class="route-contact-x" data-action="repeater#remove" aria-label="Убрать контакт"><x-ui.icon name="x" class="size-4"/></button>
    @else
        <span class="route-contact-x" aria-hidden="true"></span>
    @endif
</div>
