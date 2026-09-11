{{-- Одна кнопка «плитки/строки»: показывает, куда ведёт. Пока вид не выбран —
     две, видимость решает ширина экрана. --}}
@php
    $current = \App\Support\ListView::fromRequest(request());
    $path = '/'.ltrim(request()->path(), '/');
    $url = fn (string $v) => $path.'?'.http_build_query(array_merge(request()->query(), [\App\Support\ListView::PARAM => $v]));
    $grid = ['url' => $url('grid'), 'label' => 'Показать плитками', 'icon' => 'grid-2'];
    $list = ['url' => $url('list'), 'label' => 'Показать строками', 'icon' => 'list'];
    $buttons = match ($current) {
        'list' => [$grid + ['class' => '']],
        'grid' => [$list + ['class' => '']],
        default => [$grid + ['class' => 'sm:hidden'], $list + ['class' => 'hidden sm:inline-flex']],
    };
@endphp
@foreach ($buttons as $b)
    <a href="{{ $b['url'] }}" class="btn btn-s btn-quiet btn-round shrink-0 {{ $b['class'] }}" aria-label="{{ $b['label'] }}" title="{{ $b['label'] }}"><x-ui.icon :name="$b['icon']" class="size-5"/></a>
@endforeach
