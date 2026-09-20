{{-- Кандидат в плитках и строках: та же карточка, что у ТС — один кадр из письма (`card` на hot, у заведённого — фото ТС),
     заголовок — номер убытка или марка, чипы: вендор, госномер, город, «2 письма», «📎 27», срок ответа.
     На стоянке кнопок нет: вся строка — форма заведения, справа серым «Завести ›» (у заведённого — «ТС ›»);
     «В архив» — в окошке и в плашке формы. В CRM справа — кнопки «Завести» / «Не заявка», как раньше. --}}
@props(['c', 'base', 'mail', 'park' => false])
@php
    use App\Mail\CandidateState;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $files = $c->message?->attachments->reject->is_inline ?? collect();
    // Заголовок — машина («Changan CS35 Plus, 2023»), номер убытка чипом; без марки заголовком идёт номер.
    $title = $c->title().($c->hasCar() && $v('year') ? ', '.$v('year') : '');
    $card = $c->card();
    $main = ! $card && $c->vehicle ? $c->vehicle->mainPhoto() : null;
    $promoted = $c->state === CandidateState::Promoted;
    $href = $park ? ($promoted ? ($c->vehicle_id ? '/cars/'.$c->vehicle_id : null) : '/requests/new?candidate='.$c->id) : $base.'/'.$c->id.'/peek';
    $href ??= $base.'/'.$c->id.'/peek';
@endphp
<article id="candidate-{{ $c->id }}" class="card rise group">
    @if ($card)
    <a href="{{ $href }}" class="card-media relative" tabindex="-1"><img src="{{ \App\Media\MediaUrl::for($card) }}" alt="" loading="lazy" decoding="async"></a>
    @elseif ($main)
    <a href="{{ $href }}" class="card-media relative" tabindex="-1"><x-offer.photo :media="$main" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw"/></a>
    @else
    <a href="{{ $href }}" class="card-media card-media--blank relative" tabindex="-1"><x-ui.car-blank/></a>
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 leading-snug hover:text-accent-text"><span class="line-clamp-1">{{ $title }}</span></a>
        </div>
    </div>
    <div class="card-extra">
        @if ($c->hasNews())<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">Ещё письмо</x-ui.pill>@endif
        @if ($c->state !== CandidateState::New)<span class="tag">{{ $c->state->label() }}</span>@endif
        @if ($c->code && $c->hasCar())<span class="tag nums">{{ $c->code }}</span>@endif
        @if ($v('plate'))<span class="tag nums">{{ $v('plate') }}</span>@endif
        @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
        @if ($v('location'))<x-ui.place class="tag">{{ $v('location') }}</x-ui.place>@endif
        <span class="tag nums">{{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</span>
        @if ($files->isNotEmpty())<span class="tag nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files->count() }}</span>@endif
        @if (! $park && $v('floor_price'))<span class="tag nums font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
        @if ($park && $v('value'))<span class="tag nums">{{ \App\Support\Money::rub($v('value')) }}</span>@endif
        <x-mail.candidate-facts :v="$v"/>
    </div>
    @if ($park)
        <div class="card-aside">
            <span class="nums text-xs text-ink-dim">{{ ($c->last_message_at ?? $c->created_at)->translatedFormat('j M, H:i') }}</span>
            @if ($promoted)
                @if ($c->vehicle_id)<a href="{{ $href }}" class="text-sm text-ink-muted">ТС ›</a>@else<span class="text-sm text-ink-dim">ТС нет</span>@endif
            @elseif ($c->state === CandidateState::Rejected)
                <form method="post" action="{{ $base }}/{{ $c->id }}/decline" class="contents">@csrf<button class="text-sm text-ink-muted">Вернуть ›</button></form>
            @else
                <a href="{{ $href }}" class="text-sm text-accent-text">Завести ›</a>
            @endif
        </div>
    @else
        <div class="card-action">
            @if ($promoted)
                <a href="/offers/{{ $c->offer?->number }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">№ {{ $c->offer?->number }}</a>
            @else
                <form method="post" action="{{ $base }}/{{ $c->id }}/decline" class="contents">@csrf<button class="btn btn-s btn-ghost whitespace-nowrap">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'В архив' }}</button></form>
                <form method="post" action="{{ $base }}/{{ $c->id }}/create" class="contents">@csrf<button class="btn btn-s btn-accent min-w-0 flex-1 whitespace-nowrap">Завести</button></form>
            @endif
        </div>
    @endif
</article>
