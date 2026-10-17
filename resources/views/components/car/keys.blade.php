{{-- Ключи машины (`cars.keys_count`, ставит «Забрал» в логистике и приём на парковке): один ключ или ни одного — красный «!»
     у названия везде, где машина видна (17.10.2026, владелец: «если 1 ключ — машина должна выделяться красным
     восклицательным знаком»). label — со словами («1 ключ»), иначе знак с подсказкой. Ключей два и больше или не знаем —
     ничего. --}}
@props(['count' => null, 'label' => false])
@if ($count !== null && $count <= 1)
    @php $text = $count === 1 ? '1 ключ' : 'Нет ключей'; @endphp
    <span {{ $attributes->class(['keys-alert']) }} title="{{ $text }}" aria-label="{{ $text }}">!@if ($label)<span class="keys-alert-text">{{ mb_strtolower($text) }}</span>@endif</span>
@endif
