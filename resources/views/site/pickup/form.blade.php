{{-- Анкета покупателя по ссылке от страховой: ФИО, телефон, почта одной плашкой, день — лентой на две недели
     (дальше — «Позже» с системным календарём). После отправки — сразу пропуск с QR и он же на почте; страховой
     уходит запрос на подтверждение. После подтверждения меняется только день. --}}
@php
    $today = now()->startOfDay();
    $picked = old('pickup_on', $pass?->pickup_on?->toDateString());
    $days = collect(range(0, 13))->map(fn ($i) => $today->copy()->addDays($i));
    $later = $picked && ! $days->contains(fn ($d) => $d->toDateString() === $picked) ? \Illuminate\Support\Carbon::parse($picked) : null;
    $fields = [
        ['name', 'ФИО', 'text', 'name', 'Фамилия, имя, отчество', 'autocapitalize="words"'],
        ['phone', 'Телефон', 'tel', 'tel', '+7', 'inputmode="tel"'],
        ['email', 'Почта', 'email', 'email', 'На неё придёт QR-код', 'inputmode="email" autocapitalize="none" spellcheck="false"'],
    ];
@endphp
<x-pickup.layout :title="$dateOnly ? 'Другой день' : 'Получение'">
    <x-pickup.title :vehicle="$vehicle" :eyebrow="$pass ? 'Пропуск на получение' : 'Анкета получателя'"/>
    @if ($vehicle->yard)<div class="list mt-4"><x-pickup.yard :yard="$vehicle->yard"/></div>@endif
    @if ($closed)
        <div class="pass-state pass-state--closed mt-4 rounded-(--radius-l)"><x-ui.icon name="check-circle" class="size-5"/>ТС уже не на парковке</div>
    @else
        <form method="post" action="/pickup/{{ $vehicle->pickup_code }}" class="mt-2" data-controller="pickup-date">
            @csrf
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
            @unless ($dateOnly)
                <div class="list-head">Кто заберёт</div>
                <div class="list">
                    @foreach ($fields as [$n, $label, $type, $ac, $ph, $extra])
                        <label class="form-row {{ $errors->has($n) ? 'is-invalid' : '' }}">
                            <span class="form-row-label">{{ $label }}</span>
                            <input name="{{ $n }}" type="{{ $type }}" required autocomplete="{{ $ac }}" {!! $extra !!} class="form-row-input" placeholder="{{ $ph }}" value="{{ old($n, $pass?->{$n}) }}">
                            @error($n)<span class="form-row-error">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                </div>
            @endunless
            <div class="list-head">Когда заберёте</div>
            <div class="day-strip">
                @foreach ($days as $i => $d)
                    <label class="day-pick"><input type="radio" name="pickup_on" value="{{ $d->toDateString() }}" @checked($picked === $d->toDateString()) required>
                        <span><small>{{ [0 => 'сегодня', 1 => 'завтра'][$i] ?? $d->translatedFormat('D') }}</small><b class="nums">{{ $d->day }}</b><small>{{ $d->translatedFormat('M') }}</small></span>
                    </label>
                @endforeach
                {{-- «Позже»: календарь поверх карточки — нажатие попадает прямо в системный выбор даты (iOS
                     не открывает его программно у скрытого поля). --}}
                <label class="day-pick">
                    <input type="radio" name="pickup_on" value="{{ $later?->toDateString() }}" @checked($later) data-pickup-date-target="later">
                    <span data-pickup-date-target="laterLabel">@if ($later)<small>{{ $later->translatedFormat('D') }}</small><b class="nums">{{ $later->day }}</b><small>{{ $later->translatedFormat('M') }}</small>@else<small>&nbsp;</small><x-ui.icon name="plus" class="size-6"/><small>позже</small>@endif</span>
                    <input type="date" class="day-pick-date" min="{{ $today->toDateString() }}" max="{{ $today->copy()->addMonths(6)->toDateString() }}" value="{{ $later?->toDateString() }}" aria-label="Другой день" data-action="change->pickup-date#pick">
                </label>
            </div>
            @error('pickup_on')<p class="field-error mt-2">{{ $message }}</p>@enderror
            @unless ($dateOnly)
                <label class="check mt-6 items-start px-1 text-sm text-ink-muted">
                    <input type="checkbox" name="consent" value="1" required @checked(old('consent', (bool) $pass))>
                    <span>Даю <a href="/consent" class="text-accent-text hover:underline">согласие на обработку персональных данных</a></span>
                </label>
                @error('consent')<p class="field-error mt-2">{{ $message }}</p>@enderror
            @endunless
            <button type="submit" class="btn btn-accent mt-6 w-full">{{ $pass ? 'Сохранить' : 'Получить QR-код' }}</button>
            @if ($pass)<a href="/pickup/{{ $vehicle->pickup_code }}" class="btn btn-ghost mt-2 w-full">Назад к пропуску</a>@endif
        </form>
    @endif
</x-pickup.layout>
