{{-- Окно цепочки «Из писем»: имя ТС, тег, вендор, «Завести» и «Не заявка», под ними письма всех веток цепочки
     одной лентой (x-mail.chain) с одним «Ответить» внизу. at — письмо, на котором открыли (строка списка). --}}
@php use App\Mail\CandidateState; $c = $candidate; @endphp
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-4">
        <div class="case-head">
            <div class="case-name">
                <span class="font-medium {{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</span>
                <span class="tag {{ $c->stage === \App\Mail\CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stage === \App\Mail\CandidateStage::Intake ? $c->requestTag() : $c->stageLabel() }}</span>
                @if ($c->vendor)<x-vendor.name :vendor="$c->vendor" class="tag"/>@endif
            </div>
            @if ($c->state === CandidateState::New)
                <span class="flex shrink-0 items-center gap-1.5">
                    <form method="post" action="{{ $queue }}/{{ $c->id }}/decline" class="contents" data-turbo-frame="_top" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do"><span class="opacity-70">Не заявка</span></button></form>
                    @if ($park)
                        <a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent case-do" data-turbo-frame="_top">Завести</a>
                    @else
                        <form method="post" action="{{ $queue }}/{{ $c->id }}/create" class="contents" data-turbo-frame="_top">@csrf<button class="btn btn-s btn-accent case-do">Завести</button></form>
                    @endif
                </span>
            @endif
        </div>
        <x-mail.chain :messages="$c->messages" :base="$base" :candidate="$c" :focus="$at" reply/>
    </div>
</turbo-frame>
