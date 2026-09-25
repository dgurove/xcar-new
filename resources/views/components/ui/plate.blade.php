{{-- Госномер намёком на табличку: светлая плашка, регион за волосяной чертой (А123ВС | 777). Номер не по образцу — целиком. --}}
@props(['value'])
@php $parts = preg_match('/^([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2})(\d{2,3})$/u', mb_strtoupper(str_replace(' ', '', (string) $value)), $m) ? [$m[1], $m[2]] : [$value, null]; @endphp
<span {{ $attributes->merge(['class' => 'plate']) }}>{{ $parts[0] }}@if ($parts[1])<span class="plate-reg">{{ $parts[1] }}</span>@endif</span>
