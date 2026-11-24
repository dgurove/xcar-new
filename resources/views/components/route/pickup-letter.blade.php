{{-- Письмо поставщику о вывозе делом в ходе сделки (24.11.2026, `Logistics\PickupLetters::due`): красной строкой, нажатие — окно письма
     с шаблоном и вложениями; «Без письма — сообщили сами» — тихой кнопкой своей строкой. Ставит `x-route.path`: в блок получения,
     пока акт не отправлен, или в текущий шаг. --}}
@props(['offer', 'due'])
@php $t = $due['transport']; @endphp
<div class="mt-2">
    @if ($due['url'])
        <x-route.task-row icon="send" :emit="$due['url']" urgent class="!mt-0">
            {{ $due['title'] }}
            <x-slot:sub>{{ $due['kind'] === 'act' ? 'Забрали '.\App\Support\Ago::day($t->picked_at) : 'Вывоз '.\App\Support\Ago::day($t->pickup_at) }}</x-slot:sub>
        </x-route.task-row>
    @else
        <x-route.task-row icon="send" urgent static class="!mt-0">{{ $due['title'] }}</x-route.task-row>
    @endif
    <form method="post" action="/offers/{{ $offer->number }}/pickup-letter-skip" class="mt-2" data-turbo-confirm="Поставщику сообщили не письмом?" data-turbo-confirm-label="Сообщили сами">
        @csrf<input type="hidden" name="kind" value="{{ $due['kind'] }}">
        <x-ui.button size="sm" variant="secondary">Без письма, сообщили сами</x-ui.button>
    </form>
</div>
