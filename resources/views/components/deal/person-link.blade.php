{{-- Ссылка тому, кому отдали машину, — строкой в карточке сделки и гаража CRM (28.10.2026, владелец: прислать позже):
     его сделка или машина в гараже на сайте (`Deal::personUrl`; не вошёл — вход и сюда же). «Скопировать» и
     «Отправить» текстом (`Deal::personText`). Та же ссылка сразу после «Отдать» — шторкой `x-deal.handed`. Только админу. --}}
@props(['deal'])
@php $url = auth()->user()?->canManageCrm() ? $deal->personUrl() : null; @endphp
@if ($url && $deal->buyer)
    <div {{ $attributes->class(['list']) }} data-controller="copy" data-copy-text-value="{{ $url }}" data-copy-title-value="{{ $deal->offer->titleWithYear() }}" data-copy-done-value="Ссылка в буфере">
        <textarea hidden data-copy-target="message">{{ $deal->personText() }}</textarea>
        <div class="row items-center gap-3">
            <x-ui.row-icon name="link" tone="accent" size="s"/>
            <span class="min-w-0 flex-1">
                <span class="block">Ссылка для {{ $deal->buyer->shortName() }}</span>
                <span class="block truncate text-sm text-ink-muted">{{ preg_replace('#^https?://#', '', $url) }}</span>
            </span>
            {{-- На ПК системного листа нет: «Отправить» прячется, «Скопировать» становится главной (`copy_controller`). --}}
            <button type="button" class="btn btn-quiet btn-round shrink-0 !size-8" data-action="copy#copy" data-copy-fallback aria-label="Скопировать"><x-ui.icon name="copy" class="size-4"/></button>
            <button type="button" class="btn btn-s btn-accent shrink-0 !h-8 rounded-full !px-3.5" data-action="copy#share" data-copy-target="share">Отправить</button>
        </div>
    </div>
@endif
