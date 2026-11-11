{{-- Выбор времени подписания (`Offers\Signing`, 11.11.2026): свободное время поставщика плитками — нажатие спрашивает
     «Записать на …?» и отвечает шаг (`PickSigningTime`); «Другое время…» — своё. `action` — адрес POST (сайт или CRM). --}}
@props(['deal', 'action'])
@php $slots = \App\Offers\Signing::slots($deal); @endphp
<div {{ $attributes }}>
    @if ($deal->signing_address)<p class="mb-3 text-sm text-ink-muted"><x-ui.place>{{ $deal->signing_address }}</x-ui.place></p>@endif
    @if ($slots->isNotEmpty())
        <div class="slot-grid">
            @foreach ($slots as $slot)
                @php $m = $slot->copy()->timezone('Europe/Moscow'); $when = $m->isoFormat('dd').' '.$m->format('d.m H:i'); @endphp
                <form method="post" action="{{ $action }}" data-turbo-confirm="Записать на {{ $when }}?" data-turbo-confirm-label="Записать">
                    @csrf<input type="hidden" name="at" value="{{ $m->format('Y-m-d H:i') }}">
                    <button type="submit" class="slot">
                        <span class="slot-day">{{ $m->isoFormat('dd') }} {{ $m->format('d.m') }}</span>
                        <span class="slot-time nums">{{ $m->format('H:i') }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    @endif
    <div data-controller="sheet" class="mt-3">
        <button type="button" class="btn btn-ghost btn-s px-0 text-ink-muted" data-action="sheet#open">Другое время…</button>
        <x-ui.sheet :id="'signing-other-'.$deal->id" title="Другое время">
            <form method="post" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf
                <p class="text-sm text-ink-muted">Спросим у поставщика, свободно ли</p>
                <div class="fields"><div class="field"><label class="field-label" for="signing-other-at-{{ $deal->id }}">Когда может покупатель</label><x-ui.date name="at" :id="'signing-other-at-'.$deal->id" time default-time="10:00" :min="now()" :clearable="false"/></div></div>
                @error('at')<p class="field-error">{{ $message }}</p>@enderror
                <x-ui.sheet-foot type="submit">Записать</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
</div>
