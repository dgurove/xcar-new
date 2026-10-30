{{-- ТС стоянки в плитках и строках: кадр, название с госномером, одна строка чипов (состояние, номер, VIN, вендор,
     место), справа — слот aside: по умолчанию дни на стоянке одним цветом и красный долг, у ожидаемой —
     «Принять ›», у выданной — дата. Кнопок нет: вся карточка — ссылка на дело. facts=false — без чипов ТС (заявки). --}}
@props(['vehicle', 'href' => null, 'facts' => true, 'debt' => 0, 'total' => null, 'aside' => null, 'link' => true])
@php
    use App\Park\VehicleState;
    use App\Support\Money;
    $href ??= '/cars/'.$vehicle->id;
    $main = $vehicle->mainPhoto();
    $photos = $vehicle->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $state = $vehicle->state;
    $days = $state === VehicleState::Stored ? $vehicle->daysStored() : null;
    $noRequest = $state === VehicleState::Expected && $vehicle->relationLoaded('requests') && ! $vehicle->requests->contains(fn ($r) => $r->isOpen());
@endphp
<article id="vehicle-{{ $vehicle->id }}" {{ $attributes->class(['card group']) }}>
    @if ($link)<a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1"></a>@endif
    <x-ui.card-media :photos="$photos" :href="$href"/>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="min-w-0 truncate">{{ $vehicle->titleWithYear() }}</span><x-ui.links :vehicle="$vehicle"/><x-ui.plate :value="$vehicle->plate" class="shrink-0 text-sm font-normal"/></a>
        </div>
    </div>
    <div class="card-extra">
        @if ($facts)
            {{-- Одна строка текста вместо ряда чипов, как в таблице: состояние (кроме стоящей), что не так,
                 госномер, логотип страховой с номером убытка, парковка; не влезает — строка ужимается шрифтом (fit). Ставка — в карточке и деле. --}}
            <span class="card-sub" data-controller="fitline">
                @if ($state !== VehicleState::Stored)<span class="text-ink">{{ mb_strtolower($state->label()) }}</span>@endif
                <span class="fit-core"><x-park.ref :vehicle="$vehicle"/></span>
                <x-park.alerts :vehicle="$vehicle" plain/>
                @if ($state === VehicleState::Stored && $vehicle->yard)<span>{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</span>@endif
            </span>
        @endif
        {{ $slot }}
    </div>
    <div class="card-aside">
        @if ($aside)
            {{ $aside }}
        @elseif ($days !== null)
            <span class="nums text-sm">{{ $days }}&nbsp;д</span>
            @if ($total && $total['amount'] > 0)<span class="nums">{{ Money::rub($total['amount']) }}</span>@endif
            @if ($debt > 0)<x-ui.state tone="danger" class="nums">{{ Money::rub($debt) }}</x-ui.state>@endif
        @elseif ($state === VehicleState::Expected)
            <span class="text-sm text-ink-muted">{{ $noRequest ? 'без заявки' : 'ожидается' }}</span>
        @elseif ($state === VehicleState::Released && $vehicle->released_at)
            <span class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($vehicle->released_at, words: false) }}</span>
        @endif
    </div>
</article>
