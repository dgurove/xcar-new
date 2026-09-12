@php use App\Mail\CandidateState; @endphp
<x-ui.shell title="Из писем" :count="$candidates->total()" :trail="[['Стоянка', '/'], ['Кабинет', '/lk'], ['Из писем']]">
    <x-ui.toolbar class="mb-6" :pills="\App\Http\Admin\CandidateController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="candidates"/>
    @if ($candidates->isEmpty())
        <div class="py-24 text-center text-ink-muted">Пусто</div>
    @else
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $c)
                @php $v = fn ($f) => $c->extracted[$f]['value'] ?? null; $files = $c->message->attachments->reject->is_inline; @endphp
                <x-ui.card class="flex flex-col gap-3">
                    <div>
                        <div class="font-medium">{{ $c->code ?: ($c->subject ?: 'Письмо') }}</div>
                        <div class="text-sm text-ink-muted">{{ $v('sender') }} · {{ $c->created_at->translatedFormat('j M, H:i') }}</div>
                        @if ($c->code && $c->subject)<div class="mt-1 truncate text-sm">{{ $c->subject }}</div>@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @if ($v('brand'))<span class="chip">{{ $v('brand') }} {{ $v('model') }}</span>@endif
                        @if ($v('vin'))<span class="chip font-mono">{{ $v('vin') }}</span>@endif
                        @if ($v('plate'))<span class="chip">{{ $v('plate') }}</span>@endif
                        @foreach ((array) $v('phones') as $phone)<a href="tel:{{ preg_replace('/\D/', '', $phone) }}" class="chip text-accent-text">{{ $phone }}</a>@endforeach
                        @if ($files->isNotEmpty())<span class="chip"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif
                    </div>
                    <div class="mt-auto flex flex-wrap gap-2">
                        <a href="/pochta/{{ $c->thread_id }}" class="btn btn-ghost btn-s">Письмо</a>
                        @if ($c->state === CandidateState::Promoted)
                            <a href="/mashiny/{{ $c->vehicle_id }}" class="btn btn-quiet btn-s ml-auto">Машина</a>
                        @else
                            <form method="post" action="/kandidaty/{{ $c->id }}/otklonit" class="ml-auto">@csrf<x-ui.button size="sm" variant="ghost">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'Отклонить' }}</x-ui.button></form>
                            <form method="post" action="/kandidaty/{{ $c->id }}/zavesti">@csrf<x-ui.button size="sm">Завести заявку</x-ui.button></form>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4">{{ $candidates->links() }}</div>
    @endif
</x-ui.shell>
