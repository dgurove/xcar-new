{{-- 4. Капсулы: этап — капсула с числом, люди парят под ней; задачи — короткие капсулы в одну строку, их видно больше. --}}
@php $shown = 20; $mixed = $stage['people']->count() > 1; @endphp
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="cap-head" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">
        <h3 class="col-title">{{ $stage['title'] }}</h3>
        <span class="cap-n">{{ $stage['total'] }}</span>
    </div>
    @include('admin.space.variants.people')
    @if ($stage['total'])
        <div class="cap-list">
            @foreach ($stage['tasks']->take($shown) as $t)
                <div @class(['cap', 'is-hot' => $t['hot']]) @if ($t['hot']) title="{{ $t['hot'] }}" @endif>
                    @if ($t['vendorModel'])
                        <x-vendor.logo :vendor="$t['vendorModel']" class="task-logo"/>
                    @endif
                    <span class="cap-title">{{ $t['title'] }}</span>
                    @if ($mixed && $t['who'])
                        @include('admin.space.variants.face', ['who' => $t['who'], 'size' => 18])
                    @endif
                    <span class="cap-age">{{ \App\Space\Board::age($t['at']) }}</span>
                </div>
            @endforeach
            @include('admin.space.variants.more')
        </div>
    @endif
</div>
