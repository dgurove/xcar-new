{{-- «Не снимать с продажи» в шторке «Отдать» / «Принять» (18.11.2026, начальник: отдать в сделку или в гараж, а остальные ещё
     12 часов видят машину в предложениях и подтверждают; «Опубликовать для других» после — новая публикация, всем «Новое
     предложение»). Включён — продажа продолжается публикацией сделки с теми же волнами, без уведомлений, до сейчас + N часов
     (`PublishDeal::keep`). Только пока идёт приём или публикация для других; часы — в строке (`.keep-hours` в app.css). --}}
@props(['offer', 'prefix' => 'give'])
@if ($offer->showsDealAsOpen() || ($offer->bidsOpen() && $offer->published_at && ! $offer->is_demo))
    {{-- Часы — прямо в строке пунктиром (18.11.2026, владелец: «Не снимать с продажи 12ч», 12ч кликабельное): нажатие по
         ним — системный выбор (барабан на iPhone), по остальной строке — тумблер (выбор внутри `label` его не переключает). --}}
    <div class="list">
        <label class="row row-switch" for="{{ $prefix }}-keep">
            <span class="min-w-0 flex-1">Не снимать с продажи <select name="keep_hours" class="keep-hours" aria-label="Сколько часов">@foreach ([3, 6, 12, 24, 36, 48, 72] as $h)<option value="{{ $h }}" @selected((int) old('keep_hours', 12) === $h)>{{ $h }}ч</option>@endforeach</select></span>
            <input type="checkbox" switch class="switch" id="{{ $prefix }}-keep" name="keep" value="1" @checked(old('keep'))>
        </label>
    </div>
    @error('keep_hours')<p class="field-error">{{ $message }}</p>@enderror
@endif
