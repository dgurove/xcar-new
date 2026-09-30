{{-- Блок «Письма» в деле над таймлайном — общая карточка последнего письма (x-mail.last-letter), лента всех веток ТС окном. --}}
<x-ui.card title="Письма" :count="$letters">
    <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/cars/'.$vehicle->id.'/letters'" :asks="$asks ?? collect()" :candidate="$candidate ?? null" :reply="! $vehicle->state->isFinal()"/>
</x-ui.card>
