{{-- Текст про машину кучей — первым полем свежего черновика и новой заявки: вставили (из WhatsApp, письма, заметки), и
     поля формы заполняются сами (`Cars\CarText` за `POST /reference/car-text`, car_text_controller); дальше их
     проверяют и дописывают. Поле без имени — в форму не уходит. --}}
@props(['span' => 'col-span-full'])
<div class="field {{ $span }}" data-controller="car-text">
    <label for="f-car-text" class="field-label">Текст про машину</label>
    <div class="vin-box">
        <textarea id="f-car-text" rows="3" autofocus autocomplete="off" spellcheck="false" placeholder="Номер убытка, марка, модель, VIN, год, пробег"
            class="field-input paste-input" data-car-text-target="input" data-action="paste->car-text#pasted input->car-text#check keydown.meta+enter->car-text#parse keydown.ctrl+enter->car-text#parse"></textarea>
        <button type="button" class="vin-magic" data-car-text-target="button" data-action="car-text#parse" aria-label="Заполнить поля" title="Заполнить поля" disabled><x-ui.spark/></button>
    </div>
</div>
