@php use App\Mail\CandidateState; @endphp
{{-- Письма страховых, из которых ещё не заведено предложение: карточка — то, что вынул разбор, и «Завести». --}}
<x-ui.shell title="Из писем" :count="$candidates->total()">
    <x-ui.toolbar :pills="\App\Http\Admin\CandidateController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="candidates"/>
    @if ($candidates->isEmpty())
        <x-ui.empty class="mt-6">Писем с предложениями нет</x-ui.empty>
    @else
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $c)
                @php $x = $c->extracted; $v = fn ($f) => $x[$f]['value'] ?? null; $files = $c->message->attachments->reject->is_inline; @endphp
                <x-ui.card class="flex flex-col gap-3">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-lg leading-snug">{{ $c->title() }}@if ($v('year')), {{ $v('year') }}@endif</div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                @if ($v('insurer') ?? $v('sender'))<span class="tag">{{ $v('insurer') ?? $v('sender') }}</span>@endif
                                <span class="tag nums">{{ $c->created_at->translatedFormat('j M, H:i') }}</span>
                            </div>
                        </div>
                        @if ($v('floor_price'))<span class="nums shrink-0 text-lg font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @if ($c->code)<span class="chip">{{ $c->code }}</span>@endif
                        <x-ui.vin-code :vin="$v('vin')" class="chip"/>
                        @if ($v('plate'))<span class="chip">{{ $v('plate') }}</span>@endif
                        @if ($v('mileage'))<span class="chip nums">{{ \App\Support\Money::nums($v('mileage')) }} км</span>@endif
                        @foreach (['transmission' => \App\Cars\Transmission::class, 'drive' => \App\Cars\Drive::class, 'fuel' => \App\Cars\Fuel::class] as $f => $enum)
                            @if ($v($f))<span class="chip">{{ $enum::labelOf($v($f)) }}</span>@endif
                        @endforeach
                        @if ($v('location'))<x-ui.place class="chip">{{ $v('location') }}</x-ui.place>@endif
                        @if ($files->isNotEmpty())<span class="chip"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif
                    </div>
                    @if ($c->proposed && $c->proposed !== $x)
                        <x-ui.pill tone="urgent" class="self-start">Пришло ещё письмо — проверьте поля</x-ui.pill>
                    @endif
                    <div class="mt-auto flex flex-wrap items-center gap-2">
                        <a href="/work/mail/{{ $c->thread_id }}" class="btn btn-ghost btn-s">Письмо</a>
                        @if ($c->state === CandidateState::Promoted)
                            <x-ui.pill tone="closed" href="/offers/{{ $c->offer?->number }}" class="ml-auto">№ {{ $c->offer?->number }}</x-ui.pill>
                        @else
                            <form method="post" action="/offers/from-mail/{{ $c->id }}/decline" class="ml-auto">@csrf<x-ui.button size="sm" variant="ghost">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'Отклонить' }}</x-ui.button></form>
                            <form method="post" action="/offers/from-mail/{{ $c->id }}/create">@csrf<x-ui.button size="sm">Завести</x-ui.button></form>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4">{{ $candidates->links() }}</div>
    @endif
</x-ui.shell>
