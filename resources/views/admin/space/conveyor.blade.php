{{-- Этапы конвейера (`Conveyor::view`): одна разметка на страницу и на живое перечитывание (`HomeController::conveyor`, морф в `space_controller`). --}}
@foreach ($nodes as $key => $node)
    @include('admin.space.node')
@endforeach
