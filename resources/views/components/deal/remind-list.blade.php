{{-- Строки «Напомнить через» (`Workflow\Actions\RemindAsks::CHOICES`, 1–6 часов): шторка `x-deal.remind` и экран «Ещё подумаю…»
     окна «Беру в работу» (`x-deal.take-sheet`). Строка сразу сохраняет; стоит напоминание — над строками «Напомним в 18:30»,
     внизу «Не напоминать». ask — открытый вопрос шага (`Requirement`) или доп. просьба (`ExtraAsk`, `extra`). --}}
@props(['deal' => null, 'ask' => null, 'extra' => false, 'back' => null, 'action' => null, 'remindAt' => null])
@php
    use App\Workflow\Actions\RemindAsks;
    // action и remindAt — чужой вопрос с тем же выбором (контрпредложение, `RemindCounter`, 19.11.2026).
    $action ??= '/deals/'.$deal->id.'/remind';
    $at = ($remindAt ?? $ask?->remind_at)?->isFuture() ? ($remindAt ?? $ask->remind_at) : null;
@endphp
@if ($at)<p class="-mt-2 mb-3 text-sm text-ink-muted">Напомним {{ RemindAsks::when($at) }}</p>@endif
<div class="list">
    @foreach (RemindAsks::CHOICES as $when => $label)
        <form method="post" action="{{ $action }}" class="contents">
            @csrf<input type="hidden" name="when" value="{{ $when }}">
            @if ($extra)<input type="hidden" name="extra" value="1">@endif
            @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
            <button class="row w-full text-left">
                <span class="min-w-0 flex-1">{{ $label }}</span>
                <span class="nums shrink-0 text-ink-muted">{{ RemindAsks::when(RemindAsks::at($when)) }}</span>
            </button>
        </form>
    @endforeach
    @if ($at)
        <form method="post" action="{{ $action }}" class="contents">
            @csrf<input type="hidden" name="when" value="">
            @if ($extra)<input type="hidden" name="extra" value="1">@endif
            @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
            <button class="row w-full text-left text-danger">Не напоминать</button>
        </form>
    @endif
</div>
