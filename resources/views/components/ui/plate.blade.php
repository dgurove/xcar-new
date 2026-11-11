{{-- Госномер намёком на табличку: светлая плашка, регион за волосяной чертой (А123ВС | 777). У номера по образцу обе части
     фиксированной ширины с текстом по центру — плашки в столбик одной ширины, и то, что за ними, не скачет. Номер не по
     образцу — целиком, своей ширины. Номера нет — ничего (владелец 07.10.2026: «пустые номера не надо отображать»):
     пустая плашка с полосками занимала место названия и читалась как загрузка. --}}
{{-- copy — нажатие кладёт номер в буфер (окна парковки и логистики, дело ТС; 11.11.2026 — было руками в каждом месте). --}}
@props(['value', 'copy' => false])
@unless (blank($value))
@php $parts = preg_match('/^([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2})(\d{2,3})$/u', mb_strtoupper(str_replace(' ', '', (string) $value)), $m) ? [$m[1], $m[2]] : [$value, null]; @endphp
<span {{ $attributes->merge(['class' => 'plate'.($copy ? ' cursor-pointer' : '')]) }} @if ($copy) role="button" tabindex="0" title="Скопировать" data-controller="copy" data-copy-text-value="{{ $value }}" data-copy-done-value="Госномер в буфере" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent" @endif>@if ($parts[1])<span class="plate-main">{{ $parts[0] }}</span><span class="plate-reg">{{ $parts[1] }}</span>@else{{ $parts[0] }}@endif</span>
@endunless
