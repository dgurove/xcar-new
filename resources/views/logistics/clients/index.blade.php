{{-- Заказчики логиста (18.10.2026, владелец: «удобно заводить заказчиков»): строки `.list` — имя, контакт и телефон, сколько
     заявок, долг красным справа; первой строкой «Добавить заказчика». Нажатие — шторка: поля, «Новая заявка» с этим
     заказчиком, «Заявки N ›», «В архив». Архив — пилюлей, пока в нём кто-то есть. --}}
@php
    use App\Logistics\Client;
    use App\Support\Money;
    use App\Support\Phone;
    use App\Support\Plural;
@endphp
<x-ui.shell title="Заказчики" :phone-heading="false">
    @if ($archived || $archive)
        <x-ui.pills class="mb-4">
            <x-ui.pill href="/clients" :current="! $archive" data-turbo-action="replace">Заказчики</x-ui.pill>
            <x-ui.pill href="/clients?preset=archive" :current="$archive" data-turbo-action="replace">Архив <span class="nums">{{ $archived }}</span></x-ui.pill>
        </x-ui.pills>
    @endif
    <div class="list">
        @unless ($archive)
            <div class="contents" data-controller="sheet">
                <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Добавить заказчика</span>
                </button>
                <x-ui.sheet id="client-new" title="Заказчик">
                    @include('logistics.clients.fields', ['client' => new Client(['kind' => 'company']), 'action' => '/clients', 'id' => 'new'])
                </x-ui.sheet>
            </div>
        @endunless
        @foreach ($clients as $c)
            <div class="contents" data-controller="sheet">
                <x-ui.sheet-row>
                    <x-ui.avatar :name="$c->name" :size="44"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $c->name }}</span>
                        <span class="row-sub">{{ implode(', ', array_filter([$c->contact_name, $c->phone ? Phone::format($c->phone) : null, $c->transports_count ? $c->transports_count.' '.Plural::of($c->transports_count, ['заявка', 'заявки', 'заявок']) : 'заявок нет'])) }}</span>
                    </span>
                    @if ((int) $c->owed)<span class="shrink-0 text-right"><span class="block nums text-danger">{{ Money::rub((int) $c->owed) }}</span><span class="block text-sm text-ink-muted">должен</span></span>@endif
                    <x-ui.chevron/>
                </x-ui.sheet-row>
                <x-ui.sheet id="client-{{ $c->id }}" :title="$c->name">
                    <div class="list mb-4">
                        @unless ($archive)
                            <a href="/transports/new?client={{ $c->id }}" class="row"><x-ui.row-icon name="plus" tone="accent"/><span class="min-w-0 flex-1 font-medium">Новая заявка</span><x-ui.chevron/></a>
                        @endunless
                        @if ($c->transports_count)
                            <a href="/transports?preset=all&client={{ $c->id }}" class="row"><span class="min-w-0 flex-1">Заявки</span><span class="nums text-ink-muted">{{ $c->transports_count }}</span><x-ui.chevron/></a>
                        @endif
                        @if ($c->phone)<a href="tel:+{{ $c->phone }}" class="row"><span class="min-w-0 flex-1">Позвонить</span><span class="nums text-accent-text">{{ Phone::format($c->phone) }}</span></a>@endif
                    </div>
                    @include('logistics.clients.fields', ['client' => $c, 'action' => '/clients/'.$c->id, 'id' => $c->id])
                    <form method="post" action="/clients/{{ $c->id }}/archive" class="mt-2">
                        @csrf<x-ui.button variant="ghost" block>{{ $c->archived_at ? 'Вернуть из архива' : 'В архив' }}</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
    @if ($clients->isEmpty() && $archive)<x-ui.empty class="mt-4">В архиве пусто</x-ui.empty>@endif
</x-ui.shell>
