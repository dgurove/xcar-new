{{-- Стоянки логиста (18.10.2026): строки `.list` — название, город и адрес, сколько машин стоит справа; первой строкой
     «Добавить стоянку». Нажатие — шторка: машины на стоянке (строка — в задачу следующего отрезка), звонок и маршрут,
     поля, «В архив». Архив — пилюлей, пока в нём что-то есть. --}}
@php
    use App\Logistics\Depot;
    use App\Support\Phone;
@endphp
<x-ui.shell title="Стоянки" :phone-heading="false">
    @if ($archived || $archive)
        <x-ui.pills class="mb-4">
            <x-ui.pill href="/depots" :current="! $archive" data-turbo-action="replace">Стоянки</x-ui.pill>
            <x-ui.pill href="/depots?preset=archive" :current="$archive" data-turbo-action="replace">Архив <span class="nums">{{ $archived }}</span></x-ui.pill>
        </x-ui.pills>
    @endif
    <div class="list">
        @unless ($archive)
            <div class="contents" data-controller="sheet">
                <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Добавить стоянку</span>
                </button>
                <x-ui.sheet id="depot-new" title="Стоянка">
                    @include('logistics.depots.fields', ['depot' => new Depot, 'action' => '/depots', 'id' => 'new'])
                </x-ui.sheet>
            </div>
        @endunless
        @foreach ($depots as $d)
            @php
                $cars = $standing[$d->id] ?? collect();
                $where = implode(', ', array_filter([$d->settlement?->name, $d->address]));
            @endphp
            <div class="contents" data-controller="sheet">
                <x-ui.sheet-row>
                    <x-ui.row-icon name="map-pin" :tone="$d->waiting_count ? 'accent' : 'plain'"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $d->name }}</span>
                        @if ($where)<span class="row-sub">{{ $where }}</span>@endif
                    </span>
                    @if ($d->waiting_count)<span class="shrink-0 text-right"><span class="block nums">{{ $d->waiting_count }}</span><span class="block text-sm text-ink-muted">стоит</span></span>@endif
                    <x-ui.chevron/>
                </x-ui.sheet-row>
                <x-ui.sheet id="depot-{{ $d->id }}" :title="'Стоянка '.$d->name">
                    @if ($cars->isNotEmpty())
                        <h3 class="list-head">Стоят</h3>
                        <div class="list mb-4">
                            @foreach ($cars as $t)
                                <a href="/transports?preset=all&peek={{ $t->id }}" class="row items-center gap-3">
                                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $t->title() }}</span><span class="row-sub">{{ implode(', ', array_filter([$t->next(), $t->routeLabel() ? '→ '.$t->routeLabel() : null])) }}</span></span>
                                    <x-ui.chevron/>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($d->phone || $where)
                        <div class="list mb-4">
                            @if ($d->phone)<a href="tel:+{{ $d->phone }}" class="row"><span class="min-w-0 flex-1">{{ $d->contact_name ?? 'Позвонить' }}</span><span class="nums text-accent-text">{{ Phone::format($d->phone) }}</span></a>@endif
                            @if ($where)<a href="https://yandex.ru/maps/?text={{ rawurlencode($where) }}" target="_blank" rel="noopener" class="row"><span class="min-w-0 flex-1 break-words">{{ $where }}</span><x-ui.icon name="map-pin" class="size-5 shrink-0 text-accent-text"/></a>@endif
                        </div>
                    @endif
                    @include('logistics.depots.fields', ['depot' => $d, 'action' => '/depots/'.$d->id, 'id' => $d->id])
                    <form method="post" action="/depots/{{ $d->id }}/archive" class="mt-2">
                        @csrf<x-ui.button variant="ghost" block>{{ $d->archived_at ? 'Вернуть из архива' : 'В архив' }}</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
    @if ($depots->isEmpty() && $archive)<x-ui.empty class="mt-4">В архиве пусто</x-ui.empty>@endif
</x-ui.shell>
