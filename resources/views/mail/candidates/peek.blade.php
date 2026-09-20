{{-- Окошко кандидата: что вынул разбор, все письма списком, «Завести» / «Отклонить»; у заведённого — ссылка на ТС или предложение. --}}
@php
    use App\Mail\CandidateState;
    $v = fn ($f) => $c->extracted[$f]['value'] ?? null;
    $car = trim(($v('brand') ?? '').' '.($v('model') ?? '').($v('year') ? ', '.$v('year') : ''));
    $facts = array_filter([
        $v('mileage') ? \App\Support\Money::nums($v('mileage')).' км' : null,
        $v('transmission') ? \App\Cars\Transmission::labelOf($v('transmission')) : null,
        $v('drive') ? \App\Cars\Drive::labelOf($v('drive')) : null,
        $v('fuel') ? \App\Cars\Fuel::labelOf($v('fuel')) : null,
        $v('color'),
        $v('location'),
    ]);
@endphp
<turbo-frame id="peek" target="_top">
    @php $photos = $c->visiblePhotos()->isNotEmpty() ? $c->visiblePhotos() : $c->vehicle?->visiblePhotos(); @endphp
    <x-ui.peek :href="$mail.'/'.$c->thread_id" :title="($c->code ?: $c->title()).($c->code && $car ? ' — '.$car : '')" :photos="$photos?->isNotEmpty() ? $photos : null" :facts="$facts" action="">
        <x-slot:marks>
            @if ($c->state !== CandidateState::New)<x-ui.pill :tone="$c->state === CandidateState::Promoted ? 'closed' : 'soft'" class="!min-h-0 !py-1 text-xs">{{ $c->state->label() }}</x-ui.pill>@endif
            @if ($c->hasNews())<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">Пришло ещё письмо — проверьте поля</x-ui.pill>@endif
            @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
            <x-ui.vin-code :vin="$v('vin')" class="tag"/>
            @if ($v('plate'))<span class="tag nums">{{ $v('plate') }}</span>@endif
            @if (! $park && $v('floor_price'))<span class="tag nums font-semibold">{{ \App\Support\Money::rub($v('floor_price')) }}</span>@endif
            @if ($park && $v('value'))<span class="tag nums">{{ \App\Support\Money::rub($v('value')) }}</span>@endif
            @foreach ((array) $v('phones') as $phone)@if ($phone !== $v('insured_phone'))<a href="tel:{{ preg_replace('/\D/', '', $phone) }}" class="tag nums text-accent-text">{{ $phone }}</a>@endif @endforeach
            <x-mail.candidate-facts :v="$v"/>
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
        <div class="mt-4">
            <div class="mb-1 text-sm text-ink-dim">{{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</div>
            <x-mail.panel :messages="$c->messages" :base="$mail"/>
        </div>
        <x-slot:row><x-mail.candidate-row :c="$c" :base="$base"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
