{{-- Главное действие открытой заявки (`$task`). ТС ещё не принята — «Принять» сразу, в форму приёма дела (`?accept=1`: без
     звонка и эвакуатора — владелец 09.10.2026: «должна быть возможность сразу принять»); эвакуатор назначен — ещё «Выехали».
     Остальное (`Request::verb`: «Выдать») — в дело, где осмотр, фото, QR и долг. Исходы звонка — в задаче. --}}
@php use App\Park\{RequestState, RequestType}; @endphp
@if (in_array($task->type, [RequestType::Intake, RequestType::Tow], true) && $vehicle->state->isBefore())
    <a href="/cars/{{ $vehicle->id }}?req={{ $task->id }}&accept=1" class="btn btn-s btn-accent" data-turbo-frame="_top">Принять</a>
    @if ($task->type === RequestType::Tow && $task->state === RequestState::Scheduled)
        <form method="post" action="/requests/{{ $task->id }}/start" class="contents">@csrf<button class="btn btn-s btn-quiet">Выехали</button></form>
    @endif
@elseif ($verb = $task->verb())
    <a href="/cars/{{ $vehicle->id }}?req={{ $task->id }}" class="btn btn-s btn-accent" data-turbo-frame="_top">{{ $verb }}</a>
@endif
