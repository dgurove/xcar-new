{{-- Строка человека (`DeliveryController::people`): имя с аватаром, под ним роли; справа — сколько получил за период и в день,
     пушей, открыл, подписки на пуши (отвалившиеся красным) и когда пуш последний раз приняли. Нажатие — карточка рядом. --}}
<tr data-detail-key="{{ $r->user->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$r->user->id"><span class="cell-title flex items-center gap-2"><x-ui.avatar :user="$r->user" :size="22"/><span class="truncate">{{ $r->name }}</span></span></x-ui.row-link>
        <span class="cell-sub">{{ $r->user->rolesLabel() }}<span class="sm:hidden">, в день {{ $perDay($r) }}, открыл {{ $pct($r) }}</span></span>
    </td>
        @break
        @case('sent')
    <td class="{{ $col->classes() }}">{{ \App\Support\Money::nums($r->sent) }}<span class="cell-sub sm:hidden">получил</span></td>
        @break
        @case('day')
    <td class="{{ $col->classes() }}">{{ $perDay($r) }}</td>
        @break
        @case('push')
    <td class="{{ $col->classes() }}">@if ($r->push){{ \App\Support\Money::nums($r->push) }}@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('telegram')
    <td class="{{ $col->classes() }}">@if ($r->telegram){{ \App\Support\Money::nums($r->telegram) }}@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('opened')
    <td class="{{ $col->classes() }}">{{ $pct($r) }}</td>
        @break
        @case('subs')
    <td class="{{ $col->classes() }}">@if ($r->subs){{ $r->subs }}@if ($r->broken) <span class="text-danger">({{ $r->broken }} не принимает)</span>@endif @else<span class="text-ink-dim">нет</span>@endif</td>
        @break
        @case('last')
    <td class="{{ $col->classes() }}">{{ $r->last_push ? \App\Support\Ago::cell($r->last_push) : '—' }}</td>
        @break
        @endswitch
    @endforeach
</tr>
