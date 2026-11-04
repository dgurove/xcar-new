{{-- Задача карточкой: название, вендор с номером убытка, давность; чей ход — лицом, если этап держат разные люди;
     горящая — причиной красным. Вид — классами варианта. --}}
@php $mixed ??= $stage['people']->count() > 1; @endphp
<div @class(['task', 'is-hot' => $t['hot']])>
    <div class="task-top">
        <span class="task-title">{{ $t['title'] }}</span>
        @if ($mixed && $t['who'])
            <span class="task-who">@include('admin.space.variants.face', ['who' => $t['who'], 'size' => 20])</span>
        @endif
    </div>
    <div class="task-sub">
        @if ($t['vendorModel'])
            <x-vendor.logo :vendor="$t['vendorModel']" class="task-logo"/>
        @endif
        <span class="task-ref">{{ $t['ref'] ?: ($t['note'] ?? '') }}</span>
        <span class="task-age">{{ \App\Space\Board::age($t['at']) }}</span>
    </div>
    @if ($t['hot'])
        <div class="task-why">{{ $t['hot'] }}</div>
    @elseif ($t['note'] && $t['ref'])
        <div class="task-note">{{ $t['note'] }}</div>
    @endif
</div>
