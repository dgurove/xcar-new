{{-- Карточка кандидата в плитках и строках: та же сетка областей, что у ТС; вместо кадра — заглушка с числом писем
     и вложений; заголовок — номер убытка или марка; метки — вендор, VIN, госномер, факты разбора; действие — «Завести». --}}
@props(['c', 'base', 'mail', 'park' => false])
@php
    use App\Mail\CandidateState;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $href = $base.'/'.$c->id.'/peek';
    $files = $c->message?->attachments->reject->is_inline ?? collect();
    $title = $c->code ?: $c->title();
    $car = trim(($v('brand') ?? '').' '.($v('model') ?? '').($v('year') ? ', '.$v('year') : ''));
@endphp
<article id="candidate-{{ $c->id }}" class="card rise group">
    <a href="{{ $mail }}/{{ $c->thread_id }}" class="card-media card-media--blank relative" tabindex="-1">
        <x-ui.car-blank/>
        <span class="absolute bottom-3 left-3 flex gap-1.5"><span class="mark mark-glass nums">{{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</span>@if ($files->isNotEmpty())<span class="mark mark-glass nums"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif</span>
    </a>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $mail }}/{{ $c->thread_id }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $title }}@if ($c->code && $car) <span class="font-normal text-ink-muted">{{ $car }}</span>@endif</span></a>
        </div>
        <div class="card-marks">
            @if ($c->hasNews())<span class="mark mark-urgent">Ещё письмо</span>@endif
            @if ($c->state !== CandidateState::New)<span class="mark mark-glass">{{ $c->state->label() }}</span>@endif
            <span class="mark mark-glass nums">{{ ($c->last_message_at ?? $c->created_at)->translatedFormat('j M, H:i') }}</span>
        </div>
    </div>
    <div class="card-extra">
        @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
        <x-ui.vin-code :vin="$v('vin')" class="tag"/>
        @if ($v('plate'))<span class="tag nums">{{ $v('plate') }}</span>@endif
        @if (! $park && $v('floor_price'))<span class="tag nums font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
        @if ($park && $v('value'))<span class="tag nums">{{ \App\Support\Money::rub($v('value')) }}</span>@endif
        <x-mail.candidate-facts :v="$v"/>
    </div>
    <div class="card-place">@if ($v('location'))<x-ui.place class="truncate text-sm text-ink-dim">{{ $v('location') }}</x-ui.place>@endif</div>
    <div class="card-action">
        @if ($c->state === CandidateState::Promoted)
            <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">{{ $park ? 'ТС' : '№ '.$c->offer?->number }}</a>
        @else
            <form method="post" action="{{ $base }}/{{ $c->id }}/create" class="contents">@csrf<button class="btn btn-s btn-accent w-full whitespace-nowrap">Завести</button></form>
        @endif
    </div>
</article>
