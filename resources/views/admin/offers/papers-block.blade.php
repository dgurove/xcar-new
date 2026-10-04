{{-- Документы предложения: «Добавить документ» и у правого края искра «Из документов», полоса загрузки и список файлов (удалить — у файла). Один кусок на редактор
     и карточка строки. В редакторе (reader) — читалка «Завести» вместо окна: документы предложения и его писем читаются
     в блоке, поля машины заполняются по ходу (x-mail.reader-body); у черновика из писем (auto) — сами, как открыли. --}}
@php
    $reader = ($reader ?? false) ? \App\Mail\Scan\OfferSubject::for($offer, auth()->user()) : null;
    $readerFiles = $reader ? \App\Mail\Scan\Reader::files($reader) : collect();
@endphp
<div data-controller="photos{{ $reader ? ' reader' : '' }}" data-photos-url-value="/offers/{{ $offer->number }}/media" data-photos-collection-value="papers"
    @if ($reader) data-reader-url-value="{{ $reader->url() }}" data-reader-subject-value="{{ $reader->key() }}" data-reader-form-value="offer-form" @if ($auto ?? false) data-reader-auto-value="true" @endif data-scan-subject="{{ $reader->url() }}" data-scan-reader data-scan-papers @endif>
    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
    <div class="mb-2 flex items-center gap-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button>@if ($reader)@if ($readerFiles->isNotEmpty())<x-mail.reader-spark/>@endif @else @include('admin.offers.scan-row')@endif</div>
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
    @if ($reader)<x-mail.reader-body :subject="$reader" class="mb-3"/>@endif
    @include('admin.offers.papers')
</div>
