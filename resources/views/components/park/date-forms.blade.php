{{-- Формы дат приёма и выдачи для `x-park.date-edit`: вне формы текущего шага дела, поле привязано атрибутом `form`. --}}
@props(['vehicle'])
@if ($vehicle->accepted_at && auth()->user()?->isParkAdmin())
    @foreach (['accepted_at', 'released_at'] as $field)
        <form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-{{ $vehicle->id }}-{{ $field }}" hidden>@csrf</form>
    @endforeach
@endif
