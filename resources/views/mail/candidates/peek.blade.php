{{-- Окошко кандидата: машина, тождество (убыток, госномер, VIN), состояние (этап, срок ответа, вендор),
     «Завести» / «В архив», ниже лента писем свёрнутыми строками. Факты из письма (НДС, документы, страхователь) — в форме заведения. --}}
@php
    use App\Mail\CandidateState;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $by = $v('answer_by') ? \Illuminate\Support\Carbon::parse($v('answer_by'))->timezone('Europe/Moscow') : null;
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$mail.'/'.$c->thread_id" :title="$c->title().($c->hasCar() && $v('year') ? ', '.$v('year') : '')" :photo="$c->card() ?? $c->vehicle?->mainPhoto()" action="">
        <x-slot:marks>
            @if ($c->code && $c->hasCar())<span class="tag nums">{{ $c->code }}</span>@endif
            @if ($v('plate'))<span class="tag nums">{{ $v('plate') }}</span>@endif
            <x-ui.vin-code :vin="$v('vin')" class="tag"/>
            <span class="basis-full"></span>
            @if ($c->state !== CandidateState::New)<x-ui.pill :tone="$c->state === CandidateState::Promoted ? 'closed' : 'soft'" class="!min-h-0 !py-1 text-xs">{{ $c->state->label() }}</x-ui.pill>@endif
            @if ($park)<x-ui.pill :tone="$c->stage === \App\Mail\CandidateStage::Sold ? 'urgent' : ($c->stage === \App\Mail\CandidateStage::Released ? 'closed' : 'open')" class="!min-h-0 !py-1 text-xs">{{ $c->stageLabel() }}</x-ui.pill>@endif
            @if ($by)<x-ui.pill :tone="$by->isPast() ? 'danger' : 'urgent'" class="!min-h-0 !py-1 text-xs nums">до {{ $by->translatedFormat('j M H:i') }}</x-ui.pill>@endif
            @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
            @if (! $park && $v('floor_price'))<span class="tag nums font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
        </x-slot:marks>
        <x-slot:actions>
            @if ($c->state === CandidateState::Promoted)
                <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="btn btn-s btn-accent">{{ $park ? ($c->vehicle?->titleWithYear() ?? 'ТС') : 'Предложение № '.$c->offer?->number }}</a>
            @else
                {{-- «Завести» уводит на форму заявки или на предложение — всей страницей, не в окошко. --}}
                @if ($park)<a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent" data-turbo-frame="_top">Завести</a>@else<form method="post" action="{{ $base }}/{{ $c->id }}/create" data-turbo-frame="_top">@csrf<button class="btn btn-s btn-accent">Завести</button></form>@endif
                <form method="post" action="{{ $base }}/{{ $c->id }}/decline">@csrf<button class="pill pill-plain">{{ $c->state === CandidateState::Rejected ? 'Вернуть' : 'В архив' }}</button></form>
            @endif
        </x-slot:actions>
        <x-mail.chain class="mt-4" :messages="$c->messages" :base="$mail" :candidate="$c" :vehicle="$c->vehicle" :focus="false"/>
        <x-slot:row><x-mail.candidate-row :c="$c" :base="$base"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
