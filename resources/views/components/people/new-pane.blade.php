{{-- Панель «+ Новый …» шторки выбора (18.10.2026): данными — имя, фамилия, телефон и свои поля места (у исполнителя город и
     СДЭК, у заказчика — название, контакт, адрес); «Ссылкой» — где человек может прийти сам. Отправляет JSON
     (`people_pick_controller#create`), без формы: шторка стоит и внутри чужих форм. Успех — в той же панели: данные входа
     или ссылка с «Отправить» и «Скопировать». Поля — `_person[…]`: чужая форма их игнорирует. --}}
@props(['kind', 'link' => false, 'uid'])
@php
    $f = fn (string $key) => '_person['.$key.']';
    $id = fn (string $key) => 'pp-'.$uid.'-'.$key;
@endphp
<div class="people-new" data-people-pick-target="newPane" data-mode="person">
    <div data-people-pick-target="form" class="flex flex-col gap-4">
        @if ($link)
            <div class="segment" role="radiogroup">
                <label><input type="radio" name="_pp_mode_{{ $uid }}" value="person" checked data-action="people-pick#mode"><span>Ввести данные</span></label>
                <label><input type="radio" name="_pp_mode_{{ $uid }}" value="link" data-action="people-pick#mode"><span>Ссылкой</span></label>
            </div>
        @endif
        <div class="people-person fields">
            @if ($kind === 'client')
                <x-ui.field :name="$f('name')" :id="$id('name')" label="Название" required autocomplete="off" data-people-field="name"/>
                <x-ui.field :name="$f('contact_name')" :id="$id('contact')" label="Контакт" autocomplete="off" data-people-field="contact_name"/>
                <x-ui.field :name="$f('phone')" :id="$id('phone')" label="Телефон" type="tel" data-people-field="phone"/>
                <x-ui.field :name="$f('address')" :id="$id('address')" label="Откуда забирать" type="textarea" data-people-field="address"/>
            @else
                <x-ui.field :name="$f('first_name')" :id="$id('first')" label="Имя" required autocomplete="off" data-people-field="first_name"/>
                <x-ui.field :name="$f('last_name')" :id="$id('last')" label="Фамилия" autocomplete="off" data-people-field="last_name"/>
                <x-ui.field :name="$f('person_phone')" :id="$id('phone')" label="Телефон" type="tel" required data-people-field="person_phone"/>
                @if ($kind === 'contractor')
                    <x-ui.field :name="$f('logistics_city')" :id="$id('city')" label="Город" data-people-field="logistics_city"/>
                    <x-ui.field :name="$f('cdek_point')" :id="$id('cdek')" label="Пункт СДЭК" type="textarea" data-people-field="cdek_point"/>
                @else
                    <x-ui.field :name="$f('person_email')" :id="$id('email')" label="Почта" type="email" data-people-field="person_email"/>
                @endif
            @endif
        </div>
        <p class="field-error hidden" data-people-pick-target="error"></p>
        <button type="button" class="btn btn-accent btn-block" data-people-pick-target="submit" data-action="people-pick#create">
            <span class="people-person-label">Добавить</span>@if ($link)<span class="people-link-label">Сделать ссылку</span>@endif
        </button>
    </div>

    {{-- Готово: данные входа или ссылка — тут же, с «Отправить» и «Скопировать». --}}
    <div data-people-pick-target="done" hidden class="flex flex-col items-center gap-4 text-center">
        <span class="people-done-mark"><x-ui.icon name="check" class="size-7"/></span>
        <div><div class="text-lg" data-people-pick-target="doneTitle"></div><div class="mt-1 text-sm text-ink-muted" data-people-pick-target="doneSub"></div></div>
        <div class="list w-full text-left" data-people-pick-target="doneRows"></div>
        <div class="acts acts-hero">
            <button type="button" class="act" data-action="people-pick#share" data-people-pick-target="shareBtn"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
            <button type="button" class="act" data-action="people-pick#copy"><span class="btn btn-quiet btn-round" data-people-pick-target="copyBtn"><x-ui.icon name="copy"/></span>Скопировать</button>
        </div>
        <button type="button" class="btn btn-accent btn-block" data-action="people-pick#finish" data-people-pick-target="finish">Готово</button>
    </div>
</div>
