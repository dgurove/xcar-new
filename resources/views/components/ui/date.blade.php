{{-- Выбор даты (и времени) вместо системного поля — `date_controller`, календарь Cally. Видно — дата словами, в форму уходит
     скрытое поле прежнего вида (`Y-m-d`, со временем `Y-m-d\TH:i`): сервер не меняется. Свои атрибуты (`data-action`,
     `disabled`, `form`) — у скрытого поля, `disabled` ещё и у видимого. Время по умолчанию — `default-time` (CRM срок
     приёма 17:30, парковка 12:00). Зовёт его `x-ui.field` с `type="date|datetime-local"`.
     `chip` — вместо поля кнопка с текстом (`icon` — значок перед ним в той же кнопке: имя значка или `RequestType` — кружок приёма или выдачи, как в «Заявках») «принята 5 мая, 14:05» (`prefix` — слово перед датой, класс — снаружи): дата
     сама и есть кнопка (`x-park.date-edit`); `limit` — позже нельзя, дни после красные (срок обязывающего); пустая — `placeholder` («Указать»). `submit` — значение уходит одной отправкой формы скрытого поля, когда
     всплывашку закрыли и значение поменялось (выбор дня до времени формы не отправляет).
     `quick` — подсказки дня чипами над календарём (`[['label' => 'Заявка 02.09', 'at' => Carbon, 'source' => 'request']]`,
     дата приёма парковки — `Park\AcceptHints`): нажатие ставит значение и закрывает выбор, при `submit` — сразу уходит;
     `inline` — те же чипы ещё и рядом с полем (одно нажатие без календаря). `source` — имя скрытого поля, куда ложится
     `source` нажатой подсказки (выбор в календаре его стирает). --}}
@props(['name', 'id' => null, 'value' => null, 'time' => false, 'defaultTime' => '12:00', 'min' => null, 'max' => null, 'limit' => null, 'placeholder' => null, 'clearable' => true, 'now' => false, 'chip' => false, 'prefix' => null, 'submit' => false, 'quick' => [], 'inline' => false, 'source' => null, 'icon' => null, 'years' => null, 'start' => null])
@php
    $id ??= 'f-'.str_replace(['[', ']'], ['-', ''], $name);
    // Прежние значения бывают с секундами и пробелом («2026-10-09 17:30:00») — к виду поля.
    $v = $value instanceof \DateTimeInterface ? $value->format($time ? 'Y-m-d\TH:i' : 'Y-m-d') : (string) $value;
    $v = $v === '' ? '' : ($time ? substr(str_replace(' ', 'T', $v), 0, 16) : substr($v, 0, 10));
    $day = fn ($d) => $d ? substr(str_replace(' ', 'T', (string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d)), 0, 10) : null;
    $disabled = $attributes->has('disabled') && $attributes->get('disabled') !== false;
    $chipText = $chip && $v !== '' ? trim($prefix.' '.\App\Support\Ago::day(\Illuminate\Support\Carbon::parse($v), (bool) $time)) : ($prefix ?: $placeholder);
    // Кнопка-дата со временем — время тусклее даты, как везде в приложении (`.when-t`, 06.11.2026, владелец: «уродская»).
    $chipHtml = $chip && $time && $v !== '' ? e(trim($prefix.' '.\App\Support\Ago::day(\Illuminate\Support\Carbon::parse($v)))).' <span class="when-t">'.e(\Illuminate\Support\Carbon::parse($v)->format('H:i')).'</span>' : e($chipText);
    $quick = collect($quick)->map(fn ($q) => ['label' => $q['label'], 'value' => $q['at']->format($time ? 'Y-m-d\TH:i' : 'Y-m-d'), 'source' => $q['source'] ?? ''])->all();
    $chips = fn () => collect($quick)->map(fn ($q) => '<button type="button" class="chip nums" data-date-target="quick" data-action="date#quick" data-date-value-param="'.e($q['value']).'" data-date-source-param="'.e($q['source']).'" aria-pressed="'.(substr($q['value'], 0, 10) === substr($v, 0, 10) ? 'true' : 'false').'">'.e($q['label']).'</button>')->implode('');
@endphp
<{{ $chip ? 'span' : 'div' }} class="{{ $chip ? 'contents' : 'date-field' }}" data-controller="date" data-date-prefix-value="{{ $prefix }}" @if ($chip && $placeholder) data-date-placeholder-value="{{ $placeholder }}" @endif data-date-submit-value="{{ $submit ? 'true' : 'false' }}" data-date-time-value="{{ $time ? 'true' : 'false' }}" data-date-default-time-value="{{ $defaultTime }}" data-date-now-value="{{ $now ? 'true' : 'false' }}"
    data-date-min-value="{{ $day($min) }}" data-date-max-value="{{ $day($max) }}" @if ($start) data-date-start-value="{{ $day($start) }}" @endif @if ($limit) data-date-limit-value="{{ $limit instanceof \DateTimeInterface ? $limit->format('Y-m-d\TH:i') : substr(str_replace(' ', 'T', (string) $limit), 0, 16) }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $v }}" data-date-target="value" {{ $attributes->except(['class', 'id', 'type', 'placeholder']) }}>
    @if ($source)<input type="hidden" name="{{ $source }}" value="" data-date-target="source" {{ $attributes->only('form') }}>@endif
    @if ($chip)
        @if ($icon)
            {{-- Значок в той же кнопке (документ «Счетов и актов»: приём, выдача): нажатие и по значку, и по дате. --}}
            <button type="button" id="{{ $id }}" class="{{ $attributes->get('class') }}" data-action="date#open:prevent:stop">@if ($icon instanceof \App\Park\RequestType)<x-park.request-type :type="$icon" :word="false"/>@else<x-ui.icon :name="$icon" class="size-4 shrink-0"/>@endif<span data-date-target="text">{{ $chipText }}</span></button>
        @else
            <button type="button" id="{{ $id }}" class="{{ $attributes->get('class') }}" data-date-target="text" data-action="date#open:prevent:stop">{!! $chipHtml !!}</button>
        @endif
    @else
        {{-- Не текстовое поле, а кнопка с видом поля (12.11.2026, владелец: «это не текстовое поле — по клику календарь»): дата и
             время тусклее (`.when-t`), как у кнопки-даты; печатать дату больше нельзя. --}}
        @php $fieldEmpty = $placeholder ?? ($time ? 'Дата и время' : 'Дата'); @endphp
        <button type="button" id="{{ $id }}" class="field-input date-input {{ $v === '' ? 'date-empty' : '' }} {{ $attributes->get('class') }}" @disabled($disabled)
            data-date-target="text" data-date-empty="{{ $fieldEmpty }}" data-action="date#open:prevent:stop">@if ($v === ''){{ $fieldEmpty }}@elseif ($time){{ \App\Support\Ago::day(\Illuminate\Support\Carbon::parse($v)) }} <span class="when-t">{{ \Illuminate\Support\Carbon::parse($v)->format('H:i') }}</span>@else{{ \App\Support\Ago::day(\Illuminate\Support\Carbon::parse($v)) }}@endif</button>
    @endif
    @if ($inline && $quick)<span class="date-quick date-quick--inline">{!! $chips() !!}</span>@endif
    {{-- Внутри <summary> (дата шага в деле) нажатие по всплывашке не должно сворачивать шаг — `date#inside`. --}}
    <div class="date-pop" popover data-date-target="pop" data-action="toggle->date#toggled click->date#inside">
        @if ($quick)<div class="date-quick">{!! $chips() !!}</div>@endif
        <calendar-date class="date-cal" locale="ru-RU" first-day-of-week="1" format-weekday="short" data-date-target="cal" data-action="change->date#pick"
            @if ($day($min)) min="{{ $day($min) }}" @endif @if ($day($max)) max="{{ $day($max) }}" @endif>
            <x-ui.icon slot="previous" name="chevron-down" class="size-5 rotate-90" aria-label="Прошлый месяц"/>
            <x-ui.icon slot="next" name="chevron-down" class="size-5 -rotate-90" aria-label="Следующий месяц"/>
            <div slot="heading" class="date-heading"><calendar-select-month format-month="long"><span slot="label">Месяц</span></calendar-select-month><calendar-select-year @if ($years) max-years="{{ $years }}" @endif><span slot="label">Год</span></calendar-select-year></div>
            <calendar-month></calendar-month>
        </calendar-date>
        {{-- Необязательную дату можно стереть («Очистить»): на телефоне поле только открывает календарь. --}}
        @if ($time || $clearable)
            <div class="date-foot">
                @if ($time)<label class="date-time"><span class="sr-only">Время</span><input type="text" inputmode="numeric" maxlength="5" class="field-input" data-date-target="time" data-action="focus->date#lift input->date#typeTime keydown.enter->date#done:prevent"></label>@endif
                @if ($clearable)<button type="button" class="btn btn-s btn-quiet {{ $time ? '' : 'ml-auto' }}" data-date-target="clear" data-action="date#clear">Очистить</button>@endif
                @if ($time)<button type="button" class="btn btn-s btn-accent" data-action="date#done">Готово</button>@endif
            </div>
        @endif
    </div>
    @unless ($chip)<p class="field-error" data-date-target="error" hidden></p>@endunless
</{{ $chip ? 'span' : 'div' }}>
