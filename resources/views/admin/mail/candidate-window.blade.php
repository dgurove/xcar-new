{{-- Окно цепочки «Из писем»: шапка цепочки (x-mail.chain-head), под ней письма всех веток цепочки
     одной лентой (x-mail.chain) с одним «Ответить» внизу. at — письмо, на котором открыли (строка списка). --}}
@php use App\Mail\CandidateState; $c = $candidate; @endphp
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-4">
        @if ($c->state === CandidateState::New)
            <x-mail.chain-head :candidate="$c" :queue="$queue" :park="$park" window/>
        @elseif ($c->state === CandidateState::Rejected)
            {{-- В архиве («не заявка») — «Вернуть» тут же: цепочка снова ждёт в «Из писем» (20.10.2026). --}}
            <div class="chain-head">
                <div class="min-w-0 flex-1"><div class="chain-head-title">{{ $c->title() }}</div><div class="chain-head-sub text-ink-muted">в архиве</div></div>
                <form method="post" action="{{ $queue }}/{{ $c->id }}/decline" class="contents" data-turbo-frame="_top">@csrf<button class="btn btn-s btn-accent case-do">Вернуть</button></form>
            </div>
        @else
            <div class="chain-head"><div class="chain-head-title">{{ $c->title() }}</div></div>
        @endif
        <x-mail.chain :messages="$c->messages" :base="$base" :candidate="$c" :focus="$at" reply/>
    </div>
</turbo-frame>
