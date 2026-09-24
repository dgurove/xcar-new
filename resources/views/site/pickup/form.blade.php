{{-- Анкета покупателя по ссылке от страховой: ФИО, телефон, почта, когда заберёт. После отправки — сразу пропуск с QR
     и он же на почте; страховой уходит запрос на подтверждение. После подтверждения меняется только дата. --}}
@php
    $today = now()->startOfDay();
    $picked = old('pickup_on', $pass?->pickup_on?->toDateString());
    $quick = [$today->copy()->addDay()->toDateString() => 'Завтра', $today->copy()->addDays(2)->toDateString() => 'Послезавтра'];
    $other = $picked && ! isset($quick[$picked]);
@endphp
<x-ui.auth :title="$dateOnly ? 'Когда заберёте' : 'Получение ТС'">
    @include('site.pickup.vehicle', ['vehicle' => $vehicle])
    @if ($closed)
        <p class="mt-5 text-ink-muted">ТС уже не на парковке</p>
    @else
        <form method="post" action="/pickup/{{ $vehicle->pickup_code }}" class="mt-6 space-y-3" data-controller="pickup-date">
            @csrf
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
            @unless ($dateOnly)
                <input name="name" required autocomplete="name" autocapitalize="words" class="field-input" placeholder="Фамилия, имя, отчество" value="{{ old('name', $pass?->name) }}">
                <input name="phone" type="tel" required inputmode="tel" autocomplete="tel" class="field-input" placeholder="Телефон" value="{{ old('phone', $pass?->phone) }}">
                <input name="email" type="email" required inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false" class="field-input" placeholder="Почта, на неё придёт QR-код" value="{{ old('email', $pass?->email) }}">
            @endunless
            <div class="pt-2">
                <div class="mb-2 text-sm text-ink-muted">Когда заберёте</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($quick as $date => $label)
                        <label class="choice"><input type="radio" name="pickup_pick" value="{{ $date }}" @checked($picked === $date) data-action="pickup-date#pick"><span>{{ $label }}, {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('j M') }}</span></label>
                    @endforeach
                    <label class="choice"><input type="radio" name="pickup_pick" value="" @checked($other) data-action="pickup-date#other"><span>Другой день</span></label>
                </div>
                <input type="date" name="pickup_on" min="{{ $today->toDateString() }}" max="{{ $today->copy()->addMonths(6)->toDateString() }}" value="{{ $picked }}" class="field-input mt-3 {{ $other ? '' : 'hidden' }}" data-pickup-date-target="date">
            </div>
            @foreach (['name', 'phone', 'email', 'pickup_on', 'consent'] as $field)
                @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
            @endforeach
            @unless ($dateOnly)
                <label class="flex items-start gap-2.5 px-1 pt-1 text-sm text-ink-muted">
                    <span class="check mt-0.5"><input type="checkbox" name="consent" value="1" required @checked(old('consent', (bool) $pass))></span>
                    <span>Даю <a href="/consent" class="text-accent-text hover:underline">согласие на обработку персональных данных</a></span>
                </label>
            @endunless
            <button type="submit" class="btn btn-accent w-full">{{ $pass ? 'Сохранить' : 'Получить QR-код' }}</button>
            @if ($pass)<a href="/pickup/{{ $vehicle->pickup_code }}" class="btn btn-quiet w-full">Назад к пропуску</a>@endif
        </form>
    @endif
</x-ui.auth>
