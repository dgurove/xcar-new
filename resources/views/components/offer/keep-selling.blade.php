{{-- «Не снимать с продажи» в шторке «Отдать» / «Принять» (18.11.2026, начальник: отдать в сделку или в гараж, а остальные ещё
     12 часов видят машину в предложениях и подтверждают; «Опубликовать для других» после — новая публикация, всем «Новое
     предложение»). Включён — продажа продолжается публикацией сделки с теми же волнами, без уведомлений, до сейчас + N часов
     (`PublishDeal::keep`). Только пока идёт приём или публикация для других; поле часов — по тумблеру (`.keep-sell` в app.css). --}}
@props(['offer', 'prefix' => 'give'])
@if ($offer->showsDealAsOpen() || ($offer->bidsOpen() && $offer->published_at && ! $offer->is_demo))
    <div class="keep-sell flex flex-col gap-3">
        <div class="list">
            <label class="row row-switch">
                <span class="min-w-0 flex-1">Не снимать с продажи</span>
                <input type="checkbox" switch class="switch" name="keep" value="1" @checked(old('keep'))>
            </label>
        </div>
        <div class="keep-hours">
            <x-ui.field name="keep_hours" label="Сколько ещё в продаже, часов" :value="old('keep_hours', 12)" :id="$prefix.'-keep-hours'"/>
        </div>
    </div>
@endif
