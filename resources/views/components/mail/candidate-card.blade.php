{{-- Карточка кандидата в плитках и строках: та же сетка областей, что у ТС; кадр — фото из письма (медиатека кандидата,
     у заведённого — ТС), поверх число писем и вложений; заголовок — номер убытка или марка; метки — вендор, VIN, госномер,
     факты разбора; действие — «Завести». --}}
@props(['c', 'base', 'mail', 'park' => false])
@php
    use App\Mail\CandidateState;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $href = $base.'/'.$c->id.'/peek';
    $files = $c->message?->attachments->reject->is_inline ?? collect();
    $title = $c->code ?: $c->title();
    $car = trim(($v('brand') ?? '').' '.($v('model') ?? '').($v('year') ? ', '.$v('year') : ''));
    $photos = $c->visiblePhotos()->take(6);
    if ($photos->isEmpty() && $c->vehicle) { $photos = $c->vehicle->visiblePhotos()->take(6); }
@endphp
<article id="candidate-{{ $c->id }}" class="card rise group">
    @if ($photos->isNotEmpty())
    <div class="card-media relative" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
        <a href="{{ $mail }}/{{ $c->thread_id }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
            @foreach ($photos as $frame)<x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" data-frames-target="frame"/>@endforeach
        </a>
        <span class="pointer-events-none absolute bottom-3 left-3 flex gap-1.5"><span class="mark mark-glass nums">{{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</span>@if ($files->isNotEmpty())<span class="mark mark-glass nums"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif</span>
    </div>
    @else
    <a href="{{ $mail }}/{{ $c->thread_id }}" class="card-media card-media--blank relative" tabindex="-1">
        <x-ui.car-blank/>
        <span class="absolute bottom-3 left-3 flex gap-1.5"><span class="mark mark-glass nums">{{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</span>@if ($files->isNotEmpty())<span class="mark mark-glass nums"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif</span>
    </a>
    @endif
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
            @php $open = $park ? $c->vehicle?->requests->first(fn ($r) => $r->isOpen()) : null; @endphp
            @if ($park && !$c->vehicle_id)<span class="btn btn-s btn-quiet w-full whitespace-nowrap opacity-60">ТС нет</span>
            @else<a href="{{ $park ? ($open ? '/requests/'.$open->id : '/cars/'.$c->vehicle_id) : '/offers/'.$c->offer?->number }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">{{ $park ? ($open ? 'Заявка' : 'ТС') : '№ '.$c->offer?->number }}</a>@endif
        @else
            {{-- «Не заявка» — парсер ошибся: письмо уходит в «Отклонённые», оттуда «Вернуть». --}}
            <form method="post" action="{{ $base }}/{{ $c->id }}/decline" class="contents">@csrf<button class="btn btn-s btn-ghost whitespace-nowrap">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'Не заявка' }}</button></form>
            @if ($park)<a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent min-w-0 flex-1 whitespace-nowrap">Завести</a>@else<form method="post" action="{{ $base }}/{{ $c->id }}/create" class="contents">@csrf<button class="btn btn-s btn-accent min-w-0 flex-1 whitespace-nowrap">Завести</button></form>@endif
        @endif
    </div>
</article>
