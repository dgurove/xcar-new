@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Отчёт вендору»: о приёме — к форме отчёта над делом (#report); о выдаче — окно писем с черновиком (акт и фото). --}}
<div class="mt-3">@if (($step->plate['kind'] ?? null) === 'anchor')<a href="{{ $step->plate['url'] }}" class="btn btn-s btn-accent rounded-full">{{ $step->plate['label'] }}</a>@else<x-mail.window-button :url="$step->plate['url']" label="Отправить" class="btn-accent"/>@endif</div>
