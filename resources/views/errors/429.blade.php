@php $wait = (int) ($exception->getHeaders()['Retry-After'] ?? 60); @endphp
<x-errors.page title="Слишком много попыток" href="{{ url()->previous() }}" button="Назад">
    <p class="nums" data-controller="timer" data-timer-until-value="{{ now()->addSeconds($wait)->toIso8601String() }}" data-timer-done-value="можно снова"></p>
</x-errors.page>
