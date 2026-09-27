{{-- Госномер намёком на табличку: светлая плашка, регион за волосяной чертой (А123ВС | 777). У номера по образцу обе части
     фиксированной ширины с текстом по центру — плашки в столбик одной ширины, и то, что за ними, не скачет. Номер не по
     образцу — целиком, своей ширины. Номера нет — та же плашка, тире на месте каждого знака («–––––– | –––») (строки в списке не скачут). --}}
@props(['value'])
@php $parts = blank($value) ? ['––––––', '–––'] : (preg_match('/^([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2})(\d{2,3})$/u', mb_strtoupper(str_replace(' ', '', (string) $value)), $m) ? [$m[1], $m[2]] : [$value, null]); @endphp
<span {{ $attributes->merge(['class' => 'plate'.(blank($value) ? ' is-empty' : '')]) }}>@if ($parts[1])<span class="plate-main">{{ $parts[0] }}</span><span class="plate-reg">{{ $parts[1] }}</span>@else{{ $parts[0] }}@endif</span>
