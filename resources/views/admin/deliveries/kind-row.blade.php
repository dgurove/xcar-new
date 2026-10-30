{{-- Строка вида уведомления (`DeliveryController::kinds`): название вида, под ним — скольким людям, важное ли, скольким пуш
     слать некуда (не подписаны) и сколько людей выключили категорию; на телефоне там же пуши и открытые. «Не ушло» красным. --}}
<tr>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <span class="cell-title">{{ $r->label }}</span>
        <span class="cell-sub">{{ collect([
            $r->people.' '.\App\Support\Plural::of($r->people, ['человек', 'человека', 'человек']),
            $r->important ? 'важное' : null,
            $r->nowhere ? 'не подписаны на пуши: '.$r->nowhere : null,
            $r->muted ? 'выключили: '.$r->muted : null,
        ])->filter()->implode(', ') }}<span class="sm:hidden">, пушей {{ $r->push }}, открыли {{ $pct($r) }}</span></span>
    </td>
        @break
        @case('sent')
    <td class="{{ $col->classes() }}">{{ \App\Support\Money::nums($r->sent) }}<span class="cell-sub sm:hidden">получили</span></td>
        @break
        @case('push')
    <td class="{{ $col->classes() }}">@if ($r->push){{ \App\Support\Money::nums($r->push) }}@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('telegram')
    <td class="{{ $col->classes() }}">@if ($r->telegram){{ \App\Support\Money::nums($r->telegram) }}@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('mail')
    <td class="{{ $col->classes() }}">@if ($r->mail){{ \App\Support\Money::nums($r->mail) }}@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('failed')
    <td class="{{ $col->classes() }}">@if ($r->failed)<span class="text-danger">{{ $r->failed }}</span>@else<span class="text-ink-dim">—</span>@endif</td>
        @break
        @case('opened')
    <td class="{{ $col->classes() }}">{{ $pct($r) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
