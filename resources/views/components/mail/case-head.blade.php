{{-- Дело письма — куда это письмо (17.11.2026, владелец со скриншота iPhone: «из телефона при просмотре письма никак невозможно попасть в дело
     машины», «ради этого всё и затевалось — из писем заходить в сделку либо заводить её»). Над цепочкой в окне писем, открытом из почты
     (`from=mail`: из самой сделки ссылка вела бы в неё же), и справа в шапке листа письма на ПК (`compact`).
     - Машина CRM или ТС парковки — строка-ссылка: значок типа, название, справа серым где она («Сделка», вкладка, состояние ТС), «›».
     - Цепочка «Из писем» ждёт — её шапка с «Не заявка» и «Завести» (`x-mail.chain-head`); в листе ПК — только «Завести».
     - Ни того ни другого — «ТС не привязано», «Привязать» (форма номера, как в «⋯» письма) и у письма поставщика «Завести»
       (черновик из письма — тот же POST, что «Завести» в «Все делами»). --}}
@props(['thread' => null, 'offer' => null, 'vehicle' => null, 'candidate' => null, 'base' => '/work/mail', 'park' => false, 'compact' => false, 'messages' => null])
@php
    use App\Mail\CandidateState;
    use App\Support\Surface;
    $candidate ??= $thread?->candidate;
    $offer ??= $park ? null : ($thread?->offer ?? ($candidate?->state === CandidateState::Promoted ? $candidate->offer : null));
    $vehicle ??= $park ? ($thread?->vehicle ?? ($candidate?->state === CandidateState::Promoted ? $candidate->vehicle : null)) : null;
    $queue = $park ? '/requests/from-mail' : '/offers/from-mail';
    $car = match (true) {
        (bool) $offer => ['href' => $offer->crmPath(), 'cat' => $offer->category(), 'name' => $offer->titleWithYear(), 'place' => $offer->crmPlace(auth()->user())],
        (bool) $vehicle => ['href' => '/cars/'.$vehicle->id, 'cat' => $vehicle->category ?? \App\Cars\Category::guess($vehicle->titleWithYear()) ?? \App\Cars\Category::Passenger,
            'name' => $vehicle->titleWithYear(), 'place' => $vehicle->state->label()],
        default => null,
    };
    $fresh = ! $car && $candidate?->state === CandidateState::New ? $candidate : null;
    $loose = ! $car && ! $fresh && ! $candidate && $thread ? $thread : null;
    // «Завести» — письму поставщика: у внутренней переписки и рассылок заводить нечего.
    $letters = $messages ?? ($loose?->relationLoaded('messages') ? $loose->messages : $loose?->messages()->get());
    $enroll = $loose && collect($letters)->contains(fn ($m) => $m->isFromVendor());
@endphp
@if ($car)
    @if ($compact)
        <a href="{{ $car['href'] }}" class="letter-car pane-car" data-turbo-frame="_top"><x-ui.cat-icon :category="$car['cat']"/><span class="truncate">{{ $car['name'] }}</span><x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></a>
    @else
        <a href="{{ $car['href'] }}" class="case-head" data-turbo-frame="_top">
            <span class="case-head-icon"><x-ui.cat-icon :category="$car['cat']"/></span>
            <span class="case-head-name">{{ $car['name'] }}</span>
            <span class="case-head-place">{{ $car['place'] }}</span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </a>
    @endif
@elseif ($fresh)
    @if ($compact)
        @if ($park)
            <a href="/requests/new?candidate={{ $fresh->id }}" class="btn btn-s btn-accent case-head-do gap-1.5" data-turbo-frame="_top"><x-ui.spark class="size-4"/>Завести</a>
        @else
            <form method="post" action="{{ $queue }}/{{ $fresh->id }}/create" class="contents" data-turbo-frame="_top">@csrf<button class="btn btn-s btn-accent case-head-do gap-1.5"><x-ui.spark class="size-4"/>Завести</button></form>
        @endif
    @else
        <div class="case-head-chain"><x-mail.chain-head :candidate="$fresh" :queue="$queue" :park="$park" window/></div>
    @endif
@elseif ($loose)
    <div @class(['case-head case-head--loose' => ! $compact, 'case-head-acts' => $compact]) data-controller="unhide">
        @unless ($compact)
            <span class="case-head-icon"><x-ui.icon name="cat-car" class="size-5 text-ink-dim"/></span>
            <span class="case-head-name text-ink-muted">ТС не привязано</span>
        @endunless
        <button type="button" class="btn btn-s btn-quiet case-head-do" data-action="unhide#show" data-unhide-target="trigger">Привязать</button>
        @if ($enroll)
            <form method="post" action="{{ $base }}/{{ $loose->id }}/candidate" class="contents" data-turbo-frame="_top">@csrf<button class="btn btn-s btn-accent case-head-do gap-1.5"><x-ui.spark class="size-4"/>Завести</button></form>
        @endif
        <form method="post" action="{{ $base }}/{{ $loose->id }}/link" class="letter-link-form case-head-link" data-turbo-frame="_top" data-unhide-target="block" hidden>
            @csrf
            @if ($park)
                <div class="min-w-0 flex-1"><x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$loose->vehicle_id" :text="$loose->vehicle?->titleWithYear()"/></div>
            @else
                <x-ui.field name="number" label="Номер предложения" :value="$loose->offer?->number" span="flex-1"/>
            @endif
            <x-ui.button size="sm">Привязать</x-ui.button>
        </form>
    </div>
@endif
