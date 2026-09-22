{{-- ТС стоянки в плитках и строках: кадр, название с госномером, одна строка чипов (состояние, номер, VIN, вендор,
     место), справа — слот aside: по умолчанию дни на стоянке со светофором простоя и красный долг, у ожидаемой —
     «Принять ›», у выданной — дата. Кнопок нет: вся карточка — ссылка на дело. facts=false — без чипов ТС (заявки). --}}
{{-- note — строка под названием: что делать по заявке (x-park.request-card). --}}
@props(['vehicle', 'href' => null, 'facts' => true, 'debt' => 0, 'aside' => null, 'link' => true, 'note' => null])
@php
    use App\Park\{VehicleState, Idle};
    $href ??= '/cars/'.$vehicle->id;
    $main = $vehicle->mainPhoto();
    $photos = $vehicle->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $state = $vehicle->state;
    $days = $state === VehicleState::Stored ? $vehicle->daysStored() : null;
    $noRequest = $state === VehicleState::Expected && $vehicle->relationLoaded('requests') && ! $vehicle->requests->contains(fn ($r) => $r->isOpen());
@endphp
<article id="vehicle-{{ $vehicle->id }}" class="card rise group">
    @if ($link)<a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1"></a>@endif
    @if ($photos->isNotEmpty())
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
                @foreach ($photos as $i => $frame)
                    <x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" data-frames-target="frame"/>
                @endforeach
            </a>
            @if ($photos->count() > 1)
                <div class="card-frames">
                    <div class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1">
                        @foreach ($photos as $i => $frame)<span class="card-dot{{ $i === 0 ? ' card-dot--on' : '' }}" data-frames-target="dot"></span>@endforeach
                    </div>
                    <button type="button" class="card-arrow left-2 hidden sm:flex" data-action="frames#prev" aria-label="Предыдущее фото"><x-ui.icon name="chevron-left" class="size-[18px]"/></button>
                    <button type="button" class="card-arrow right-2 hidden sm:flex" data-action="frames#manual" aria-label="Следующее фото"><x-ui.icon name="chevron-right" class="size-[18px]"/></button>
                </div>
            @endif
        </div>
    @else
        <a href="{{ $href }}" class="card-media card-media--blank" tabindex="-1"><x-ui.car-blank/></a>
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 leading-snug hover:text-accent-text"><span class="line-clamp-1">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate) <span class="nums font-normal text-ink-muted">{{ $vehicle->plate }}</span>@endif</span></a>
        </div>
        @if ($note)<div class="truncate text-sm">{{ $note }}</div>@endif
    </div>
    <div class="card-extra">
        @if ($facts)
            {{-- Стоящей ТС состояние не пишем: в «Наличии» все такие, место говорит само. --}}
            @if ($state !== VehicleState::Stored)<x-ui.pill :tone="match ($state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }" class="!min-h-0 !py-0.5 text-xs">{{ $state->label() }}</x-ui.pill>@endif
            @if ($noRequest)<span class="tag text-urgent">без заявки</span>@endif
            @if ($state === VehicleState::Stored && ! $vehicle->yard_id)<span class="tag tag-urgent">Парковка не указана</span>@endif
            @if (! $state->isFinal() && $vehicle->noLetters())<span class="tag tag-urgent">Писем нет</span>@endif
            @if (! $state->isFinal() && $vehicle->vinProblem())<span class="tag tag-danger">{{ $vehicle->vinProblem() }}</span>@endif
            @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
            @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
            @if ($state === VehicleState::Stored && $vehicle->yard)<x-ui.place class="tag">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@endif
        @endif
        {{ $slot }}
    </div>
    <div class="card-aside">
        @if ($aside)
            {{ $aside }}
        @elseif ($days !== null)
            <span class="nums text-sm {{ match (Idle::tone($days)) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', default => 'text-ink-muted' } }}">{{ $days }} дн</span>
            @if ($debt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs nums">{{ \App\Support\Money::rub($debt) }}</x-ui.pill>@endif
        @elseif ($state === VehicleState::Expected)
            <span class="text-sm text-ink-muted">{{ $noRequest ? 'без заявки' : 'ожидается' }}</span>
        @elseif ($state === VehicleState::Released && $vehicle->released_at)
            <span class="nums text-sm text-ink-dim">{{ $vehicle->released_at->translatedFormat('j M') }}</span>
        @endif
    </div>
</article>
