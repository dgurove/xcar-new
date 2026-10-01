{{-- Окно цепочки «Из писем»: шапка цепочки (x-mail.chain-head), под ней письма всех веток цепочки
     одной лентой (x-mail.chain) с одним «Ответить» внизу. at — письмо, на котором открыли (строка списка). --}}
@php use App\Mail\CandidateState; $c = $candidate; @endphp
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-4">
        @if ($c->state === CandidateState::New)
            <x-mail.chain-head :candidate="$c" :queue="$queue" :park="$park" window/>
        @else
            <div class="chain-head"><div class="chain-head-title">{{ $c->title() }}</div></div>
        @endif
        <x-mail.chain :messages="$c->messages" :base="$base" :candidate="$c" :focus="$at" reply/>
    </div>
</turbo-frame>
