{{-- copy — иконка внутри поля справа: кладёт в буфер то, что сейчас введено (госномер, ПТС). Слот suffix — своё
     внутри поля справа вместо неё (номер убытка — значок Мигторга, x-offer.migtorg-field).
     required — звёздочка у подписи; без поля сервер не сохранит (атрибута required нет: поле в свёрнутом блоке
     браузер не покажет, и форма молча не уйдёт). --}}
@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'options' => null, 'placeholder' => null, 'afterLabel' => null, 'span' => null, 'copy' => false, 'required' => false, 'digits' => false])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['[', ']'], ['-', ''], $name));
    // Поле набора (`rows[0][price]`): ошибки и old() лежат под точками — иначе строка лестницы прайса
    // после ошибки теряла введённое и не подсвечивалась.
    $path = str_replace(['[', ']'], ['.', ''], $name);
    $error = $errors->first($name) ?: $errors->first($path);
    $bound = old($path, old($name, $value));
    // Клавиатура по смыслу поля — одно правило на приложение (16.10.2026): по последнему имени (`stages[pickup_phone]`
    // — телефон, `rows[0][price]` — цена). Цифровой клавиатуры нет нигде (решение владельца 29.09.2026: в ней нет
    // запятой, «1,6» не набрать) — у чисел, сумм и реквизитов клавиатура с запятой (decimal); коды (VIN, госномер,
    // номер убытка, полиса, СТС, ПТС) — заглавными без автозамены; телефон и почта — свой тип. Явные атрибуты важнее.
    preg_match_all('/\[([^\]]*)\]/', $name, $parts);
    $base = strtolower(collect($parts[1])->reject(fn ($p) => $p === '' || ctype_digit($p))->last() ?? preg_replace('/\[.*$/', '', $name));
    $ends = fn (string ...$tails) => collect($tails)->contains(fn ($t) => $base === $t || str_ends_with($base, '_'.$t));
    // Сумма — разрядами при наборе (имя суммы или `digits` у поля с другим именем: оценочная, пробег), целыми рублями:
    // сервер копейки отбрасывает; ставка за сутки — с копейками. Своё число знаков — data-digits-decimals-value у поля.
    $rate = str_ends_with($base, '_rate') && $base !== 'buyer_rate_multiplier';
    // `share` — только «Наша доля, ₽»: min_bid_share — доля от цены («0,6»), не рубли.
    $money = $digits || $rate || $base === 'share' || $ends('price', 'amount', 'sum', 'cost', 'fee', 'commission');
    $keys = match (true) {
        $money => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
        $ends('year', 'mileage', 'km', 'power', 'volume', 'qty', 'capacity', 'days', 'hours', 'port', 'multiplier', 'share') => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
        $ends('inn', 'kpp', 'ogrn', 'bik', 'bic', 'account', 'corr') => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'autocorrect' => 'off', 'spellcheck' => 'false'],
        $ends('vin', 'plate', 'claim_ref', 'ref', 'policy_no', 'sts', 'pts', 'dl', 'passport', 'passport_code', 'external_no', 'contract_no', 'agreement_number') => ['autocapitalize' => 'characters', 'autocorrect' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off'],
        $ends('phone', 'tel') => ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel'],
        $ends('email') => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'none', 'autocorrect' => 'off'],
        in_array($base, ['q', 'search'], true) => ['type' => 'search', 'enterkeyhint' => 'search', 'autocomplete' => 'off'],
        default => [],
    };
    if ($type !== 'text') unset($keys['type']);
    $type = $keys['type'] ?? $type;
    unset($keys['type']);
    // Свои контроллеры поля и общие (разряды суммы, копирование) — одной строкой: иначе переданный data-action
    // перекрывал бы общий.
    $controllers = array_filter(explode(' ', (string) $attributes->get('data-controller')));
    $actions = array_filter(explode(' ', (string) $attributes->get('data-action')));
    if ($money && in_array($type, ['text', 'tel'], true) && ! in_array('digits', $controllers, true)) {
        $controllers[] = 'digits';
        array_unshift($actions, 'input->digits#format');
        if (! $attributes->has('data-digits-decimals-value')) $keys['data-digits-decimals-value'] = $rate ? '2' : '0';
    }
    if ($copy) $actions[] = 'input->copy#sync';
    if ($controllers) $keys['data-controller'] = implode(' ', array_unique($controllers));
    if ($actions) $keys['data-action'] = implode(' ', array_unique($actions));
    $attributes = $attributes->except(['data-controller', 'data-action']);
    $wiring = array_intersect_key($keys, array_flip(['data-controller', 'data-action']));
@endphp
<div class="field {{ $span }} {{ $error ? 'field-invalid' : '' }}">
    @if ($label && $afterLabel)<span class="field-label flex items-center gap-1.5"><label for="{{ $id }}">{{ $label }}@if ($required)<x-ui.req/>@endif</label>{{ $afterLabel }}</span>
    @elseif ($label)<label for="{{ $id }}" class="field-label">{{ $label }}@if ($required)<x-ui.req/>@endif</label>@endif
    @if (in_array($type, ['date', 'datetime-local'], true))
        {{-- Дата и время — свой выбор (`x-ui.date`, календарь), не системное поле: год текущий и время по умолчанию
             (`default-time`) уже стоят, день — одним нажатием (владелец 10.10.2026). --}}
        <x-ui.date :name="$name" :id="$id" :value="$bound" :time="$type === 'datetime-local'" :default-time="$attributes->get('default-time', '12:00')"
            :min="$attributes->get('min')" :max="$attributes->get('max')" :limit="$attributes->get('limit')" :placeholder="$placeholder" :clearable="! $required" :now="$attributes->has('now')" {{ $attributes->except(['id', 'min', 'max', 'limit', 'default-time', 'now'])->merge($wiring) }}/>
    @elseif ($options !== null)
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id')->merge(['class' => 'field-input'] + $wiring) }}>
            @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected((string) $bound === (string) $optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" placeholder="{{ $placeholder }}" {{ $attributes->except('id')->merge(['class' => 'field-input'] + $wiring) }}>{{ $bound }}</textarea>
    @elseif (isset($suffix) && $suffix->isNotEmpty())
        <div class="vin-box">
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" placeholder="{{ $placeholder }}" value="{{ $bound }}"
                {{ $attributes->except('id')->merge(['class' => 'field-input'] + $keys) }}>
            {{ $suffix }}
        </div>
    @elseif ($copy)
        <div class="vin-box" data-controller="copy" data-copy-done-value="В буфере">
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" placeholder="{{ $placeholder }}" value="{{ $bound }}" data-copy-target="field"
                {{ $attributes->except('id')->merge(['class' => 'field-input'] + $keys) }}>
            <button type="button" class="vin-magic field-copy" data-copy-target="button" data-action="copy#copy" aria-label="Скопировать" title="Скопировать"><x-ui.icon name="copy" class="size-5"/></button>
        </div>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" placeholder="{{ $placeholder }}"
            @if ($type !== 'password' && $type !== 'file') value="{{ $bound }}" @endif
            {{ $attributes->except('id')->merge(['class' => 'field-input'] + $keys) }}>
    @endif
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
