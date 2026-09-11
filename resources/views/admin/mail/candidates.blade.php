@php use App\Mail\CandidateState; @endphp
<x-ui.shell title="Кандидаты" :count="$candidates->total()" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Кандидаты']]">
    <x-ui.toolbar :pills="\App\Http\Admin\CandidateController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="candidates"/>
    @if ($candidates->isEmpty())
        <x-ui.empty class="mt-6">Пусто.</x-ui.empty>
    @else
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $c)
                @php $x = $c->extracted; $v = fn ($f) => $x[$f]['value'] ?? null; $files = $c->message->attachments->reject->is_inline; @endphp
                <x-ui.card class="flex flex-col gap-3">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $c->title() }}@if ($v('year')), {{ $v('year') }}@endif</div>
                            <div class="text-sm text-ink-muted">{{ $v('insurer') ?? $v('sender') }} · {{ $c->created_at->translatedFormat('j M, H:i') }}</div>
                        </div>
                        @if ($v('floor_price'))<span class="font-semibold tabular-nums">{{ number_format($v('floor_price'), 0, '', ' ') }} ₽</span>@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @if ($c->code)<span class="chip">{{ $c->code }}</span>@endif
                        @if ($v('vin'))<span class="chip font-mono">{{ $v('vin') }}</span>@endif
                        @if ($v('plate'))<span class="chip">{{ $v('plate') }}</span>@endif
                        @if ($v('mileage'))<span class="chip">{{ number_format($v('mileage'), 0, '', ' ') }} км</span>@endif
                        @foreach (['transmission' => \App\Cars\Transmission::class, 'drive' => \App\Cars\Drive::class, 'fuel' => \App\Cars\Fuel::class] as $f => $enum)
                            @if ($v($f))<span class="chip">{{ $enum::labelOf($v($f)) }}</span>@endif
                        @endforeach
                        @if ($v('location'))<span class="chip">{{ $v('location') }}</span>@endif
                        @if ($files->isNotEmpty())<span class="chip"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif
                    </div>
                    @if ($c->proposed && $c->proposed !== $x)
                        <div class="text-sm text-urgent">Пришло ещё письмо — проверьте поля</div>
                    @endif
                    <div class="mt-auto flex flex-wrap gap-2">
                        <a href="/admin/pochta/{{ $c->thread_id }}" class="btn btn-ghost btn-sm">Письмо</a>
                        @if ($c->state === CandidateState::Promoted)
                            <a href="/admin/offers/{{ $c->offer?->number }}" class="btn btn-secondary btn-sm ml-auto">№ {{ $c->offer?->number }}</a>
                        @else
                            <form method="post" action="/admin/kandidaty/{{ $c->id }}/otklonit" class="ml-auto">@csrf<x-ui.button size="sm" variant="ghost">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'Отклонить' }}</x-ui.button></form>
                            <form method="post" action="/admin/kandidaty/{{ $c->id }}/zavesti">@csrf<x-ui.button size="sm">Завести черновик</x-ui.button></form>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4">{{ $candidates->links() }}</div>
    @endif
</x-ui.shell>
