{{-- Конвейер (`Conveyor::view`): этапы столбцами слева направо. Одна разметка на страницу и на живое перечитывание
     (`HomeController::conveyor`, морф в `space_controller`). Подэтап ведёт в свой список только тем, кому эти списки
     открыты (`canManageCrm`): модератору сделки, вывоз и вендоры закрыты. --}}
@php $links = auth()->user()?->canManageCrm() ?? false; @endphp
<div class="conveyor" data-board>
    @foreach ($board as $stage)
        @include('admin.space.stage')
    @endforeach
</div>
