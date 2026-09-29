{{-- Пилюля «Документы N» (или «Письмо», «Фото N») — открывает шторку документов (x-ui.docs) на первом файле. Вкладки идут в
     порядке ссылок на странице, поэтому сначала скрытым набором весь список (`Support\Docs`), потом сама пилюля. --}}
@props(['docs'])
@if ($docs)
    @php
        $files = collect($docs)->whereNotIn('type', ['letter', 'photos']);
        $first = $files->first() ?? $docs[0];
        [$icon, $label] = match (true) {
            $files->isNotEmpty() => ['file', 'Документы '.$files->count()],
            $first['type'] === 'letter' => ['mail', 'Письмо'],
            default => ['photo', $first['label']],
        };
    @endphp
    <span hidden>@foreach ($docs as $doc)<x-ui.doc :doc="$doc"/>@endforeach</span>
    <x-ui.doc :doc="$first" {{ $attributes->merge(['class' => 'pill pill-plain gap-1.5']) }}><x-ui.icon :name="$icon" class="size-4"/> {{ $label }}</x-ui.doc>
@endif
