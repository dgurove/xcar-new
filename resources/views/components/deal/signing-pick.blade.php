{{-- Поставщик предложил своё время (`Offers\Signing`, «Поставщик предложил другое время»): его варианты плитками — нажатие
     спрашивает «Записать на …?» и отвечает шаг (`PickSigningTime`); «Другое время…» — день и время плитками (`other` —
     адрес `SaveSigningWish`, на шаге выбора это тот же ответ). `action` — адрес POST плитки (сайт или CRM). --}}
@props(['deal', 'action', 'other', 'back' => null])
@php $slots = \App\Offers\Signing::slots($deal); @endphp
<div {{ $attributes }}>
    @if ($deal->signing_address)<p class="mb-3 text-sm text-ink-muted"><x-ui.place>{{ $deal->signing_address }}</x-ui.place></p>@endif
    @if ($slots->isNotEmpty())
        <p class="mb-2 text-sm text-ink-muted">Свободно у поставщика</p>
        <div class="slot-grid">
            @foreach ($slots as $slot)
                @php $m = $slot->copy()->timezone('Europe/Moscow'); $when = \App\Offers\Signing::short($m); @endphp
                <form method="post" action="{{ $action }}" data-turbo-confirm="Записать на {{ $when }}?" data-turbo-confirm-label="Записать">
                    @csrf<input type="hidden" name="at" value="{{ $m->format('Y-m-d H:i') }}">@if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
                    <button type="submit" class="slot">
                        <span class="slot-day">{{ $m->isoFormat('dd') }} {{ $m->format('d.m') }}</span>
                        <span class="slot-time nums">{{ $m->format('G:i') }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    @endif
    {{-- Своё время — сетка на месте, не шторкой: шторка перехватывает жесты ряда часов. --}}
    <details class="signing-edit mt-3">
        <summary class="text-sm text-ink-muted"><span class="signing-edit-open">Другое время…</span><span class="signing-edit-close">Свернуть</span></summary>
        <p class="mb-3 mt-2 text-sm text-ink-muted">Спросим у поставщика, свободно ли</p>
        <x-deal.signing-slots :deal="$deal" :action="$other" :back="$back" submit="Записать"/>
    </details>
</div>
