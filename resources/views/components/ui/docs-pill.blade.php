{{-- Пилюля «Документы N» (или «Письмо», «Фото N») — открывает шторку документов (x-ui.docs) на первом файле. Вкладки идут в
     порядке ссылок на странице, поэтому сначала скрытым набором весь список (`Support\Docs`), потом сама пилюля. --}}
{{-- files — только когда есть файлы: «Письмо» пилюлей там, где письма и так карточкой ниже, не нужно (дело ТС на парковке,
     12.11.2026, владелец: «зачем кнопка „Письмо“»). --}}
@props(['docs', 'files' => false])
@php $onlyFiles = $files; $files = \App\Support\Docs::files($docs ?: null); @endphp
@if ($docs && (! $onlyFiles || $files->isNotEmpty()))
    @php
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
