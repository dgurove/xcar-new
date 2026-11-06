{{-- Документ из фото в слоте отчёта (06.11.2026, владелец: «что значит надпись „Акт приёма“ — непонятно»): строкой вложения,
     как в письме — значок PDF, название, сколько страниц, нажатие — просмотр; «×» — не прикладывать (строка уходит).
     Галка в форме спрятана и стоит: документ уходит с отчётом. --}}
@props(['doc', 'kind'])
@php $pages = (int) $doc->getCustomProperty('pages'); @endphp
<x-ui.file :name="($doc->getCustomProperty('label') ? $doc->getCustomProperty('label').'.pdf' : null) ?: $doc->getCustomProperty('title') ?: $doc->file_name" :href="'/files/'.$doc->id" :mime="'application/pdf'" :size="$pages ? $pages.' стр.' : null" class="report-file">
    <input type="checkbox" name="docs[{{ $kind }}][]" value="{{ $doc->id }}" checked class="sr-only" tabindex="-1" data-report-target="doc">
    <button type="button" class="btn btn-ghost btn-s px-2 text-ink-muted" data-action="report#unattach" aria-label="Не прикладывать"><x-ui.icon name="x" class="size-5"/></button>
</x-ui.file>
