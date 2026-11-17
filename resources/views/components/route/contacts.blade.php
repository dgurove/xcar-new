{{-- Контакты владельца в шторке шага (17.11.2026, Т-Страхование: в письме двое — «Нужина Елена Александровна +7(906)… /
     Нужин Владислав Николаевич +7(967)…»): вместо полей «Владелец» и «Телефон владельца» — группа на человека, два обычных
     поля без подписей, подсказкой «ФИО» и «Номер телефона», текст справа (владелец: «где-то справа, где-то снизу»); × — в строке ФИО у второго и дальше, «Добавить контакт» последней (`repeater_controller`). Заполнено из письма
     (`OwnerContacts::of`: их пишет письмо — `FillFromLetter`). Уходят `contacts[i][name|phone]`: первый — в поля шага, все — в шаг и машине. --}}
@props(['contacts' => [], 'labels' => ['Владелец', 'Телефон владельца'], 'id' => 'contacts'])
@php
    // Введённое после ошибки — только своей форме: на странице предложения блок есть и в шторке шага, и в «Получении».
    $mine = old('_contacts') === $id;
    $rows = $mine && old('contacts') ? array_values((array) old('contacts')) : $contacts;
    $rows = $rows ?: [['name' => null, 'phone' => null]];
    $error = $mine ? $errors->first('contacts') : null;
@endphp
<div class="flex flex-col gap-3" data-controller="repeater">
    <input type="hidden" name="_contacts" value="{{ $id }}">
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
    <div class="flex flex-col gap-3" data-repeater-target="list">
        @foreach ($rows as $i => $c)
            <x-route.contact-row :id="$id" :i="$i" :name="$c['name'] ?? ''" :phone="$c['phone'] ?? ''" :labels="$labels" :removable="! $loop->first"/>
        @endforeach
    </div>
    <template data-repeater-target="template">
        <x-route.contact-row :id="$id" i="__i__" :labels="$labels" removable/>
    </template>
    <button type="button" class="btn btn-quiet btn-s self-start" data-action="repeater#add"><x-ui.icon name="plus" class="size-4"/>Добавить контакт</button>
</div>
