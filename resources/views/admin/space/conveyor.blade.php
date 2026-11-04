{{-- Этапы конвейера (`Conveyor::view`): одна разметка на страницу и на живое перечитывание (`admin.space.stream`). --}}
@foreach ($nodes as $key => $node)
    @include('admin.space.node')
@endforeach
