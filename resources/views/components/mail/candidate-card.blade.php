{{-- Кандидат CRM в плитках и строках: та же карточка, что у ТС — один кадр из письма (`card` на hot, у заведённого —
     фото ТС), заголовок — машина или номер убытка, под ним последнее письмо словами; чипы — только состояние и
     тождество: номер убытка, госномер, вендор, срок ответа. Непрочитанное — оранжевая точка. Справа — «Завести» /
     «В архив». На стоянке своя строка — `x-park.candidate-row`. --}}
@props(['c', 'base', 'mail'])
@php
    use App\Mail\CandidateState;
    use App\Mail\Chains\NodeTitle;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $title = $c->title().($c->hasCar() && $v('year') ? ', '.$v('year') : '');
    $card = $c->card();
    $main = ! $card && $c->vehicle ? $c->vehicle->mainPhoto() : null;
    $promoted = $c->state === CandidateState::Promoted;
    $href = $base.'/'.$c->id.'/peek';
    $last = $c->lastLetter();
    $unread = $c->messages->contains(fn ($m) => ! $m->is_seen && ! $m->isOurs());
    $by = $v('answer_by') ? \Illuminate\Support\Carbon::parse($v('answer_by'))->timezone('Europe/Moscow') : null;
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
            <a href="{{ $href }}" class="block min-w-0 flex-1 leading-snug hover:text-accent-text"><span class="line-clamp-1 {{ $c->hasCar() ? '' : 'text-ink-muted' }}">@if ($unread)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ $title }}</span></a>
        </div>
        @if ($last)
            <div class="truncate text-sm {{ $unread ? 'text-ink' : 'text-ink-muted' }}"><span class="text-ink-dim">{{ NodeTitle::who($last) }}:</span> {{ NodeTitle::for($last, $c) }}</div>
        @endif
    </div>
    <div class="card-extra">
        @if ($c->state !== CandidateState::New)<span class="tag">{{ $c->state->label() }}</span>@endif
        @if ($by)<x-ui.pill :tone="$by->isPast() ? 'danger' : 'urgent'" class="!min-h-0 !py-0.5 text-xs nums">до {{ $by->translatedFormat('j M H:i') }}</x-ui.pill>@endif
        @if ($c->code)<x-ui.copy-code class="tag" :value="$c->code"/>@endif
        @if ($v('plate'))<span class="tag nums">{{ $v('plate') }}</span>@endif
        @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
        @if ($v('floor_price'))<span class="tag nums font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
    </div>
    <div class="card-action">
        @if ($promoted)
            <a href="/offers/{{ $c->offer?->number }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">№ {{ $c->offer?->number }}</a>
        @elseif ($c->state === CandidateState::Closed)
            <span class="text-sm text-ink-dim">Закрыта</span>
        @else
            <form method="post" action="{{ $base }}/{{ $c->id }}/decline" class="contents">@csrf<button class="btn btn-s btn-ghost whitespace-nowrap">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'В архив' }}</button></form>
            <form method="post" action="{{ $base }}/{{ $c->id }}/create" class="contents">@csrf<button class="btn btn-s btn-accent min-w-0 flex-1 whitespace-nowrap">Завести</button></form>
        @endif
    </div>
</article>
