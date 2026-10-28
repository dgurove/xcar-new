{{-- Ссылка, которую отдают человеку, — одна на всё приложение (28.10.2026, владелец: «красивые единые модули»; было пять
     видов в двадцати местах). `copy_controller`: «Отправить» — системный лист (текст и ссылка одним сообщением), где его
     нет (ПК) — прячется, и «Скопировать» становится главной (`data-copy-fallback`).
     - hero — шторка: круглые кнопки с подписью «Отправить», «Скопировать» и свои после них (слот: «QR-код», «Открыть»);
       url — рамкой сверху (`showUrl`), text — готовое сообщение (`editable` — полем, его можно поправить), `below` — абзац под кнопками;
     - row — строка списка: значок, `label`, под ним `sub` (по умолчанию адрес без https), справа «Скопировать» кружком и
       «Отправить» пилюлей; `lead` — своя левая часть (кнопка, что открывает шторку);
     - buttons — только эти две кнопки (в строку, у которой своя разметка: ссылка в деньгах сделки). --}}
@props(['url', 'text' => null, 'title' => null, 'done' => null, 'variant' => 'hero', 'editable' => false, 'rows' => 3, 'showUrl' => false,
    'label' => null, 'sub' => null, 'icon' => 'link', 'lead' => null, 'below' => null])
@php $copy = ['data-controller' => 'copy', 'data-copy-text-value' => $url, 'data-copy-title-value' => $title, 'data-copy-done-value' => $done]; @endphp
@if ($variant === 'buttons')
    <span {{ $attributes->merge($copy)->class('ml-auto flex shrink-0 items-center gap-2') }}>
        @if ($text !== null)<textarea hidden data-copy-target="message">{{ $text }}</textarea>@endif
        @include('components.ui.share-link-buttons')
    </span>
@elseif ($variant === 'row')
    <div {{ $attributes->merge($copy)->class('row flex-wrap items-center gap-x-3 gap-y-2') }}>
        @if ($text !== null)<textarea hidden data-copy-target="message">{{ $text }}</textarea>@endif
        @if ($lead)
            {{ $lead }}
        @else
            <x-ui.row-icon :name="$icon" tone="accent" size="s"/>
            <span class="min-w-[9rem] flex-1">
                <span class="block">{{ $label }}</span>
                <span class="block truncate text-sm text-ink-muted">{{ $sub ?? preg_replace('#^https?://#', '', $url) }}</span>
            </span>
        @endif
        {{-- В узкой колонке кнопки уходят второй линией справа, а не сжимают подпись. --}}
        <span class="ml-auto flex shrink-0 items-center gap-2">@include('components.ui.share-link-buttons')</span>
    </div>
@else
    <div {{ $attributes->merge($copy)->class('flex flex-col gap-3') }}>
        @if ($showUrl)<div class="box-nested nums break-all text-base leading-snug select-all" data-action="click->copy#select">{{ $url }}</div>@endif
        @if ($text !== null)
            @if ($editable)
                <textarea class="field-input" rows="{{ $rows }}" aria-label="Сообщение" data-copy-target="message">{{ $text }}</textarea>
            @else
                <textarea hidden data-copy-target="message">{{ $text }}</textarea>
            @endif
        @endif
        <div class="acts acts-hero">
            <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
            <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
            {{ $slot }}
        </div>
        @if ($below){{ $below }}@endif
    </div>
@endif
