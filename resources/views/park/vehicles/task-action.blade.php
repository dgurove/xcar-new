{{-- Главное действие открытой заявки (`$task`) вверху карточки. Приём — в самой карточке формой (`task`), кнопки тут нет;
     остальное (`Request::verb`: «Выдать») — в дело, где осмотр, фото, QR и долг. --}}
@php use App\Park\RequestType; @endphp
@if ($task->type !== RequestType::Intake && ($verb = $task->verb()))
    <a href="/cars/{{ $vehicle->id }}?req={{ $task->id }}" class="btn btn-s btn-accent" data-turbo-frame="_top">{{ $verb }}</a>
@endif
