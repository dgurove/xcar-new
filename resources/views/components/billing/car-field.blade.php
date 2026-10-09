{{-- ТС полем формы (09.10.2026, владелец: в шторке поиска «безумно неудобно вбивать новую: всё смещается и не видишь, что
     вбиваешь»): строка «ТС» в группе `.fields`, набранное и есть значение — машина словами (`car_title`). Машины системы
     (`/work/money/cars`: номер, ДЛ, VIN, марка) — подсказкой под полем, не больше четырёх строк; выбрал — в поле её
     название и галка справа (`offer_id`), поменял хоть букву — снова слова. Второй шторки и программного фокуса нет:
     поле ставит палец, лист встаёт на клавиатуру штатно (`app.js keyboardInset`), подсказки растут вниз от поля.
     preview — адрес исхода (карточка ссылки: «Засчитать 53 000 ₽» на кнопке с целью submit, строка note под полем).
     Стоит прямо в `.fields` (обёртка `contents`); group — своя группа `.fields` вокруг поля, а слот (строка исхода,
     кнопки) — под ней, внутри того же контроллера. --}}
@props(['offerId' => null, 'text' => null, 'preview' => null, 'error' => null, 'group' => false])
@php $uid = substr(md5(uniqid((string) $offerId, true)), 0, 8); @endphp
<div class="contents" data-controller="car-field" data-car-field-url-value="/work/money/cars" @if ($preview) data-car-field-preview-value="{{ $preview }}" @endif {{ $attributes }}>
    @if ($group)<div class="fields">@endif
    <div @class(['field', 'field-invalid' => $error])>
        <label for="car-field-{{ $uid }}" class="field-label">ТС</label>
        <span class="field-input car-field-box">
            <input id="car-field-{{ $uid }}" type="text" name="car_title" value="{{ $text }}" placeholder="Марка, модель" autocomplete="off" autocorrect="off"
                   autocapitalize="words" spellcheck="false" enterkeyhint="done" class="car-field-text" data-car-field-target="input"
                   data-action="input->car-field#input focus->car-field#focus blur->car-field#blur keydown->car-field#key">
            <span class="car-field-mark" data-car-field-target="mark" @unless ($offerId) hidden @endunless><x-ui.icon name="check" class="size-5"/></span>
            <button type="button" class="car-field-clear" data-car-field-target="clear" data-action="car-field#clear" aria-label="Очистить" @if (! $text || $offerId) hidden @endif><x-ui.icon name="x" class="size-4"/></button>
        </span>
        @if ($error)<p class="field-error">{{ $error }}</p>@endif
    </div>
    <input type="hidden" name="offer_id" value="{{ $offerId }}" data-car-field-target="offer">
    <div class="car-field-hints" data-car-field-target="hints" hidden></div>
    @if ($group)</div>@endif
    {{ $slot }}
</div>
