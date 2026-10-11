{{-- Блок «Письма» в деле над таймлайном — все письма веток ТС строками, как в «Почте» (x-mail.letters); строка открывает
     окно писем на этом письме, «Ответить» — окно с редактором. --}}
<x-ui.card title="Письма" :count="$letters">
    <x-mail.letters :messages="$messages" :url="'/cars/'.$vehicle->id.'/letters'" :asks="$asks ?? collect()" :stages="collect($candidate?->stages ?? [])->pluck('message_id')" :reply="! $vehicle->state->isFinal()"/>
</x-ui.card>
