{{-- Карточка человека в «Доставке» (`DeliveryController::detail`): чьи подписки на пуши и что ему ушло за период — строка на
     уведомление: время, вид и каналы словами («пуш на 2 устройства, Telegram»), не ушедшее красным; открытое — лаймовым
     «открыл» с каналом, откуда. Ведёт на карточку человека в «Пользователях». --}}
@php
    use App\Support\Ago;
    $words = ['feed' => 'лента', 'banner' => 'карточка', 'push' => 'пуш', 'telegram' => 'Telegram', 'mail' => 'письмо'];
    $failedSubs = $subs->whereNotNull('failed_at')->count();
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/settings/users/'.$person->id" :title="$person->name" :photo="false" :blank="false" action="Открыть человека">
        <x-slot:line>
            <span>{{ $person->rolesLabel() }}</span>
            <span>{{ $subs->count() ? 'подписок на пуши '.$subs->count() : 'пуши не включены' }}</span>
            @if ($failedSubs)<span class="text-danger">не принимает {{ $failedSubs }}</span>@endif
        </x-slot:line>
        <h3 class="mt-4 text-sm text-ink-muted">{{ $period }}, последние {{ $items->count() }}</h3>
        <div class="mt-2 flex flex-col divide-y divide-line/40 text-sm">
            @forelse ($items as $rows)
                @php
                    $first = $rows->first();
                    $opened = $rows->first(fn ($d) => $d->opened_at !== null);
                @endphp
                <div class="flex items-baseline gap-3 py-2">
                    <span class="nums w-24 shrink-0 text-ink-muted">{{ \App\Support\Ago::html($first->created_at, true, short: true) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block">{{ $first->label }}</span>
                        <span class="block text-xs text-ink-muted">
                            @foreach ($rows->sortBy(fn ($d) => array_search($d->channel, array_keys($words), true))->values() as $d)
                                <span @class(['text-danger' => $d->status === 'failed', 'text-ink-dim' => $d->status === 'skipped'])>{{ $words[$d->channel].($d->channel === 'push' && $d->devices > 1 ? ' на '.$d->devices : '').match ($d->status) { 'failed' => ' не ушёл', 'skipped' => ' некуда', default => '' }.($loop->last ? '' : ',') }}</span>
                            @endforeach
                        </span>
                    </span>
                    @if ($opened)
                        <span class="shrink-0 text-xs text-accent-text">открыл, {{ $words[$opened->opened_via] ?? '' }}</span>
                    @endif
                </div>
            @empty
                <x-ui.empty>За этот период ничего не уходило</x-ui.empty>
            @endforelse
        </div>
    </x-ui.row-card>
</x-ui.detail>
