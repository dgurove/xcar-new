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
    <div data-controller="sheet" class="mt-3">
        <button type="button" class="btn btn-ghost btn-s px-0 text-ink-muted" data-action="sheet#open">Другое время…</button>
        <x-ui.sheet :id="'signing-other-'.$deal->id" title="Другое время">
            <p class="mb-3 text-sm text-ink-muted">Спросим у поставщика, свободно ли</p>
            @include('cabinet.deals.signing-when-form', ['action' => $other, 'submit' => 'Записать', 'back' => $back])
        </x-ui.sheet>
    </div>
</div>
