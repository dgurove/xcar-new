{{-- 5. Фото-карточки: у задачи кадр машины; заголовок парит, люди висят над ним на нити. --}}
@php $mixed = $stage['people']->count() > 1; @endphp
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="ph-head">
        <div class="ph-people">@include('admin.space.variants.people', ['size' => 32, 'max' => 3])</div>
        <div class="col-line">
            <h3 class="col-title" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
            @include('admin.space.variants.count')
        </div>
    </div>
    @if ($stage['total'])
        <div class="col-tasks">
            @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
                <div @class(['ph-card', 'is-hot' => $t['hot']])>
                    <div class="ph-img" @if ($t['photo'] ?? null) style="background-image: url('{{ $t['photo'] }}')" @endif>
                        @if (! ($t['photo'] ?? null))
                            <x-ui.cat-icon :category="\App\Cars\Category::tryFrom((string) $t['cat'])" class="size-7"/>
                        @endif
                        @if ($mixed && $t['who'])
                            <span class="ph-who">@include('admin.space.variants.face', ['who' => $t['who'], 'size' => 24])</span>
                        @endif
                    </div>
                    <div class="ph-body">
                        <span class="task-title">{{ $t['title'] }}</span>
                        <span class="task-sub">
                            @if ($t['vendorModel'])
                                <x-vendor.logo :vendor="$t['vendorModel']" class="task-logo"/>
                            @endif
                            <span class="task-ref">{{ $t['ref'] }}</span>
                        </span>
                        @if ($t['hot'])
                            <span class="task-why">{{ $t['hot'] }}</span>
                        @else
                            <span class="task-note">{{ $t['note'] ?: 'ждёт '.\App\Space\Board::age($t['at']) }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
            @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
        </div>
    @endif
</div>
