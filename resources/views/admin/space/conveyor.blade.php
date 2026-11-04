{{-- Конвейер (`Conveyor::view`): откуда поступает — в воздухе слева, этапы столбцами, чем кончается — справа. Одна разметка
     на страницу и на живое перечитывание (`HomeController::conveyor`, морф в `space_controller`). --}}
@php $icons = ['mail' => 'mail', 'migtorg' => 'cart', 'park' => 'park', 'gallery' => 'photo', 'done' => 'check-circle', 'garage' => 'key']; @endphp
<div class="conveyor" data-board>
    <div class="conveyor-air">
        <div class="air-label">Поступает</div>
        @foreach ($board['sources'] as $key => [$name, $today])
            <div class="air-row">
                <span class="air-ico"><x-ui.icon :name="$icons[$key]" class="size-4"/></span>
                <span>{{ $name }}</span>
                @if ($today > 0)
                    <span class="air-new" data-step="src:{{ $key }}">+<span data-count="{{ $today }}">{{ $today }}</span></span>
                @endif
            </div>
        @endforeach
    </div>
    @foreach ($board['stages'] as $stage)
        @include('admin.space.stage')
    @endforeach
    <div class="conveyor-air">
        @foreach ($board['ends'] as $key => [$name, $n])
            <div class="air-row">
                <span class="air-ico"><x-ui.icon :name="$icons[$key]" class="size-4"/></span>
                <span>{{ $name }}</span>
                <span class="air-n" data-step="end:{{ $key }}"><span data-count="{{ $n }}">{{ $n }}</span></span>
            </div>
        @endforeach
    </div>
</div>
