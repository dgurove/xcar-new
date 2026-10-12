{{-- Шаг «Выдача»: QR, когда, кто заберёт, деньги, фото при выдаче (`steps/release` с кнопками подвалом). «Выдать и
     сформировать письмо» — дальше окно писем с актом выдачи, «Выдать без письма» — акт выдачи ждёт задачей. Шторки
     «Покупатель», «Покупатель отказался», «Выдать без QR» — тут же (`release-sheets`), отвечают в окно. --}}
<x-park.act-frame title="Выдача" :vehicle="$vehicle" :self="$self">
    <form method="post" action="/cars/{{ $vehicle->id }}/release" id="act-release-{{ $vehicle->id }}" class="act-fill">
        @csrf
        @if ($advance)<input type="hidden" name="advance" value="1">@endif
        @include('park.vehicles.steps.release', ['window' => true])
    </form>
    @include('park.vehicles.release-sheets', ['formId' => 'act-release-'.$vehicle->id, 'window' => true])
</x-park.act-frame>
