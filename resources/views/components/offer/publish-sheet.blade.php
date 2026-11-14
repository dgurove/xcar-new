{{-- «Выставить в продажу» с выбором времени (владелец 03.10.2026; одно слово для действия — 05.11.2026, владелец: «не понимаю
     разницы между „Выставить в продажу“ и „Опубликовать“»): сейчас, в ближайший слот (по умолчанию) или в следующий — строками
     со временем выхода, кнопка называет исход (подпись меняется по отмеченной строке, `.publish-choice` в app.css). Своей формой
     (`action`: выход маршрута) или полями чужой (`form`: редактор, `submit` — then=open). Срок страховой раньше слота —
     дата красным: таймер черновика уведёт предложение раньше. Без offer — пачкой (галочки «Оцененных» и «Публикации»,
     `form` — их форма); move — перенос стоящих в слоте: «Перенести на завтра в 16:00». «Другой день» — календарь (`Slots::DAY`). --}}
@props(['offer' => null, 'id', 'action' => null, 'form' => null, 'submit' => [], 'title' => 'Выставить в продажу', 'move' => false, 'state' => null])
@php
    $choices = \App\Offers\Slots::choices();
    $late = fn ($at) => $offer && $at && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($at);
@endphp
<x-ui.sheet :id="$id" :title="$title">
    {{-- Какую машину и за сколько выставляем — крупно сверху (09.11.2026: Evolute 2611093788 выставили, думая, что это другая
         машина: окошко сменило карточку, а шторка говорила только «Выставить в продажу»). --}}
    @if ($offer)
        <div class="mb-4 rounded-(--radius-m) bg-surface-2 px-3 py-2.5">
            <div class="font-medium">{{ $offer->titleWithYear() }}</div>
            <div class="text-sm text-ink-muted">@if ($offer->claim_ref)<span class="nums">{{ $offer->claim_ref }}</span>, @endif цена продажи <span class="nums text-ink">{{ $offer->asking_price ? \App\Support\Money::rub($offer->asking_price) : 'не вписана' }}</span></div>
        </div>
    @endif
    @if ($action)<form method="post" action="{{ $action }}" class="publish-choice flex flex-col gap-4" data-price-guard="publish" @if ($offer) data-price-guard-offer="{{ $offer->number }}" @endif>@csrf @if ($state)<input type="hidden" name="state" value="{{ $state }}">@endif @else<div class="publish-choice flex flex-col gap-4">@endif
        <div class="list">
            @foreach ($choices as $c)
                <label class="row row-check">
                    <span class="min-w-0 flex-1">
                        <span @class(['block', 'text-danger' => $late($c['at'])])>{{ $c['title'] }}</span>
                    </span>
                    <span class="check"><input type="radio" name="when" value="{{ $c['when'] }}" @if ($form) form="{{ $form }}" @endif @checked($c['when'] === \App\Offers\Slots::NEAREST)></span>
                </label>
            @endforeach
            {{-- «Другой день» (13.10.2026): слот 16:00 выбранного числа, календарь ниже. --}}
            <label class="row row-check">
                <span class="min-w-0 flex-1">Другой день</span>
                <span class="check"><input type="radio" name="when" value="{{ \App\Offers\Slots::DAY }}" @if ($form) form="{{ $form }}" @endif></span>
            </label>
            {{-- «Срочно» (12.10.2026, владелец): сейчас, всем сразу, громко, свой срок приёма. Только одному предложению. --}}
            @if ($offer && ! $move)
                <label class="row row-check">
                    <x-ui.icon name="fire" fill class="size-5 shrink-0 text-fire"/>
                    <span class="min-w-0 flex-1">Срочно</span>
                    <span class="check"><input type="radio" name="when" value="{{ \App\Offers\Slots::URGENT }}" @if ($form) form="{{ $form }}" @endif></span>
                </label>
            @endif
        </div>
        <div class="publish-day fields">
            <x-ui.field name="day" :id="$id.'-day'" label="Когда" type="datetime-local" :default-time="sprintf('%02d:00', (int) config('xcar.slot_hour'))" :value="\App\Offers\Slots::dayDefault()->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')" :limit="$offer ? \App\Offers\Binding::closeLimit($offer) : null" :form="$form"/>
        </div>
        @if ($offer && ! $move)
            <div class="urgent-until fields">
                <x-ui.field name="urgent_until" :id="$id.'-urgent-until'" label="Приём до" type="datetime-local" :value="\App\Offers\Slots::urgentDefault()->format('Y-m-d\TH:i')" :min="now()->addMinutes(30)->format('Y-m-d\TH:i')" :limit="$offer ? \App\Offers\Binding::closeLimit($offer) : null" :form="$form"/>
            </div>
        @endif
        {{-- Где продаём (14.11.2026): своей формой — здесь; у редактора поле уже в его форме, у «Города факт». --}}
        @if ($offer && ! $move && $action)<x-offer.sale-city :offer="$offer" :id="$id.'-sale-city'" suggest/>@endif
        {{-- Шторка закрывается сразу: ошибка поля (номер убытка, фото) должна быть видна, а не под шторкой. --}}
        <x-ui.button block :form="$form" :name="array_key_first($submit)" :value="$submit ? reset($submit) : null" data-action="sheet#close">
            @foreach ($choices as $c)<span data-when="{{ $c['when'] }}">{{ $c['at'] ? ($move ? 'Перенести на ' : 'Выставить ').\App\Offers\Slots::phrase($c['at']) : 'Выставить сейчас' }}</span>@endforeach
            <span data-when="{{ \App\Offers\Slots::DAY }}">{{ $move ? 'Перенести' : 'Выставить в продажу' }}</span>
            <span data-when="{{ \App\Offers\Slots::URGENT }}">Выставить срочно</span>
        </x-ui.button>
    @if ($action)</form>@else</div>@endif
</x-ui.sheet>
