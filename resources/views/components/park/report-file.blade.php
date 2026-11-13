{{-- Документ слота отчёта (06.11.2026, владелец: «что значит надпись „Акт приёма“ — непонятно»): строкой вложения, как в
     письме — значок (у снимка — миниатюра), название, сколько страниц или вес, нажатие — просмотр; «×» — не прикладывать
     (строка уходит). С 13.11.2026 так же и снимки «Файлом» (Сергей: «фотки прикрепляются ущербно» — были обрезанными
     чипами «5456269815564673091.jp»). pick — слот «Прочее»: прикладывать ли, решают галкой слева (по умолчанию нет);
     в остальных слотах галка спрятана и стоит; checked — только что приложенный в «Прочее» отмечен сразу. vehicle — карандаш
     (`x-ui.file-rename`, маршрут парковки). --}}
@props(['doc', 'kind', 'pick' => false, 'checked' => false, 'vehicle' => null])
@php
    $pages = (int) $doc->getCustomProperty('pages');
    $label = $doc->getCustomProperty('label');
    $name = ($label ? $label.'.pdf' : null) ?: \App\Media\DocName::of($doc);
    $image = str_starts_with((string) $doc->mime_type, 'image/');
@endphp
<x-ui.file :name="$name" :href="'/files/'.$doc->id" :mime="$doc->mime_type" :thumb="$image ? '/files/'.$doc->id : null" :size="$pages ? $pages.' стр.' : $doc->humanReadableSize" class="report-file" data-doc-id="{{ $doc->id }}">
    @if ($pick)
        <label class="report-pick" aria-label="Приложить"><input type="checkbox" name="docs[{{ $kind }}][]" value="{{ $doc->id }}" @checked($checked) class="report-pick-box" data-action="report#count" data-report-target="doc"><x-ui.icon name="check" class="report-pick-icon size-3.5"/></label>
    @else
        <input type="checkbox" name="docs[{{ $kind }}][]" value="{{ $doc->id }}" checked class="sr-only" tabindex="-1" data-report-target="doc">
    @endif
    @if ($vehicle)<x-ui.file-rename :action="'/cars/'.$vehicle->id.'/media/'.$doc->id" :name="$name"/>@endif
    <button type="button" class="btn btn-ghost btn-s px-2 text-ink-muted" data-action="report#unattach" aria-label="Не прикладывать"><x-ui.icon name="x" class="size-5"/></button>
</x-ui.file>
