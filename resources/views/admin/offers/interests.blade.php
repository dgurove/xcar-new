{{-- Интерес покупателей к предложению: кто, чей покупатель, телефон, состояние; «Связались» и «Закрыть». --}}
@php use App\Offers\InterestState; @endphp
<x-ui.card title="Интерес" >
    <div class="flex flex-col gap-2">
        @foreach ($offer->interests as $interest)
            <div class="box-nested">
                <div class="flex flex-wrap items-center gap-1.5"><x-ui.person :user="$interest->user" full/>@if ($interest->user->manager)<span class="text-sm text-ink-muted">покупатель</span><x-ui.person :user="$interest->user->manager"/>@endif @if ($interest->user->phone)<a href="tel:+{{ $interest->user->phone }}" class="tag nums">{{ $interest->user->phoneFormatted() }}</a>@endif<span class="tag">{{ $interest->state->label() }}</span><span class="tag nums">{{ $interest->created_at->translatedFormat('j M, H:i') }}</span></div>
                @if ($interest->comment)<div class="mt-1 text-sm">{{ $interest->comment }}</div>@endif
                @if ($interest->state === InterestState::New)
                    <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="contacted"><x-ui.button size="sm" variant="secondary">Связались</x-ui.button></form>
                @elseif ($interest->state === InterestState::Contacted)
                    <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><x-ui.button size="sm" variant="ghost">Закрыть</x-ui.button></form>
                @endif
            </div>
        @endforeach
    </div>
</x-ui.card>
