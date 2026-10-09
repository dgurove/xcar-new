{{-- Главное действие открытой заявки (`$task`): эвакуатор назначен — «Выехали» тут же; остальное (`Request::verb`: «Выдать»,
     «Принять», «Назначить») — в дело, где осмотр, фото, QR и долг. У звонка кнопки нет: исходы — в задаче. --}}
@php use App\Park\{RequestState, RequestType}; @endphp
@if ($task->type === RequestType::Tow && $task->state === RequestState::Scheduled)
    <form method="post" action="/requests/{{ $task->id }}/start" class="contents">@csrf<button class="btn btn-s btn-accent">Выехали</button></form>
@elseif (! $task->awaitsCall() && ($verb = $task->verb()))
    <a href="/cars/{{ $vehicle->id }}?req={{ $task->id }}" class="btn btn-s btn-accent" data-turbo-frame="_top">{{ $verb }}</a>
@endif
