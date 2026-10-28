{{-- Закупочная выросла — вопрос менеджеру (28.10.2026, `RepriceAsk`): тем же видом, что контрпредложение (`counter-card`),
     первым в задаче сделки, гаража и над «Ваше подтверждение». «Согласен» — новая цена встаёт (договор и счета сделки
     перевыставятся), «Отказываюсь» — сделка отменяется / подтверждение снимается, с переспросом. Только его вопрос. --}}
@props(['deal' => null, 'bid' => null])
@php
    $me = auth()->user();
    $ask = $me ? \App\Offers\RepriceAsk::pending()->where('user_id', $me->id)
        ->when($deal, fn ($q) => $q->where('deal_id', $deal->id), fn ($q) => $q->where('bid_id', $bid?->id ?? 0))
        ->with(['deal', 'bid'])->latest('id')->first() : null;
    $ask = $ask?->alive() ? $ask : null;
@endphp
@if ($ask)
    <div {{ $attributes->class('counter-card') }}>
        <div class="text-sm text-accent-text">Закупочная выросла</div>
        @if ($ask->shown_to !== null)
            <div class="nums mt-1 text-[32px] font-bold leading-tight">{{ \App\Support\Money::rub($ask->shown_to) }}</div>
            <div class="nums mt-1 text-sm text-ink-muted">{{ $ask->bid_id ? 'цена «от»' : 'закупочная' }} была {{ \App\Support\Money::rub($ask->shown_from) }}</div>
        @else
            <div class="nums mt-1 text-[32px] font-bold leading-tight">+{{ \App\Support\Money::rub($ask->to_price - $ask->from_price) }}</div>
            <div class="mt-1 text-sm text-ink-muted">к закупочной по сделке</div>
        @endif
        <p class="mt-2 text-sm">Согласны? Ответьте до <span class="nums">{{ $ask->due_at->setTimezone('Europe/Moscow')->translatedFormat('j M, H:i') }}</span></p>
        <div class="mt-4 flex flex-col gap-2">
            <form method="post" action="/reprice-asks/{{ $ask->id }}" data-turbo-confirm="Согласиться на новую цену?" data-turbo-confirm-text="{{ $ask->deal_id ? 'Счёт и договор обновятся' : 'Ваше подтверждение останется' }}" data-turbo-confirm-label="Согласен">
                @csrf<input type="hidden" name="agree" value="1">
                <button type="submit" class="btn btn-accent w-full">Согласен</button>
            </form>
            <form method="post" action="/reprice-asks/{{ $ask->id }}" data-turbo-confirm="Отказаться?" data-turbo-confirm-text="{{ $ask->declineMeans() }}" data-turbo-confirm-label="Отказываюсь">
                @csrf<input type="hidden" name="agree" value="0">
                <button type="submit" class="btn btn-quiet w-full text-danger">Отказываюсь</button>
            </form>
        </div>
        @error('reprice')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
    </div>
@endif
