@php use App\Mail\CandidateState; @endphp
{{-- Письма на стоянку, из которых ещё не заведена заявка. --}}
<x-ui.shell title="Из писем" :count="$candidates->total()">
    <x-ui.toolbar :pills="\App\Http\Admin\CandidateController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="candidates"/>
    @if ($candidates->isEmpty())
        <x-ui.empty class="mt-6">Писем с заявками нет.</x-ui.empty>
    @else
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $c)
                @php $v = fn ($f) => $c->extracted[$f]['value'] ?? null; $files = $c->message->attachments->reject->is_inline; @endphp
                <x-ui.card class="flex flex-col gap-3">
                    <div class="min-w-0">
                        <div class="text-lg leading-snug">{{ $c->code ?: ($c->subject ?: 'Письмо') }}</div>
                        <div class="mt-0.5 text-sm text-ink-muted">{{ implode(' · ', array_filter([$v('sender'), $c->created_at->translatedFormat('j M, H:i')])) }}</div>
                        @if ($c->code && $c->subject)<div class="mt-1 truncate text-sm">{{ $c->subject }}</div>@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @if ($v('brand'))<span class="chip">{{ $v('brand') }} {{ $v('model') }}</span>@endif
                        @if ($v('vin'))<span class="chip font-mono">{{ $v('vin') }}</span>@endif
                        @if ($v('plate'))<span class="chip">{{ $v('plate') }}</span>@endif
                        @foreach ((array) $v('phones') as $phone)<a href="tel:{{ preg_replace('/\D/', '', $phone) }}" class="chip text-accent-text">{{ $phone }}</a>@endforeach
                        @if ($files->isNotEmpty())<span class="chip"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif
                    </div>
                    <div class="mt-auto flex flex-wrap items-center gap-2">
                        <a href="/pochta/{{ $c->thread_id }}" class="btn btn-ghost btn-s">Письмо</a>
                        @if ($c->state === CandidateState::Promoted)
                            <x-ui.pill tone="closed" href="/mashiny/{{ $c->vehicle_id }}" class="ml-auto">Машина</x-ui.pill>
                        @else
                            <form method="post" action="/zayavki/iz-pisem/{{ $c->id }}/otklonit" class="ml-auto">@csrf<x-ui.button size="sm" variant="ghost">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'Отклонить' }}</x-ui.button></form>
                            <form method="post" action="/zayavki/iz-pisem/{{ $c->id }}/zavesti">@csrf<x-ui.button size="sm">Завести</x-ui.button></form>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4">{{ $candidates->links() }}</div>
    @endif
</x-ui.shell>
