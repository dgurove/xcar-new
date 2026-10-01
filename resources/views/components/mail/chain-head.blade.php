{{-- Шапка незаведённой цепочки — в карточке «Из писем» и в окне цепочки: ТС по имени, под ней серой строкой логотип
     вендора с номером убытка (`x-vendor.ref`) и этап словом; справа «Не заявка», у парковки «✨» (окно «Распознать»,
     x-mail.scan-window) и «Завести». В списке «Не заявка» —
     только на компьютере (на телефоне цепочку отклоняет свайп), в окне — всегда; window — формы уводят из окна. --}}
@props(['candidate', 'queue', 'park' => true, 'window' => false])
@php
    use App\Mail\CandidateStage;
    $c = $candidate;
    $stage = $c->stage === CandidateStage::Intake || ! $c->stage ? $c->requestTag() : $c->stageLabel();
    $frame = $window ? 'data-turbo-frame=_top' : '';
@endphp
<div class="chain-head">
    <div class="min-w-0 flex-1">
        <div class="chain-head-title {{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</div>
        <div class="chain-head-sub">
            <x-vendor.ref :vendor="$c->vendor" :ref="$c->code"/>
            <span class="{{ $c->stage === CandidateStage::Sold ? 'text-urgent' : '' }}">{{ $stage }}</span>
        </div>
    </div>
    <form method="post" action="{{ $queue }}/{{ $c->id }}/decline" class="{{ $window ? 'contents' : 'hidden md:contents' }}" {!! $frame !!} data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do"><span class="opacity-70">Не заявка</span></button></form>
    @if ($park)
        <button type="button" class="btn btn-s btn-quiet case-do" aria-label="Распознать" title="Распознать" data-controller="emit" data-action="emit#send" data-emit-event-param="scan:open" data-emit-url-param="/requests/from-mail/{{ $c->id }}/scan">✨</button>
        <a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent case-do" {!! $frame !!}>Завести</a>
    @else
        <form method="post" action="{{ $queue }}/{{ $c->id }}/create" class="contents" {!! $frame !!}>@csrf<button class="btn btn-s btn-accent case-do">Завести</button></form>
    @endif
</div>
