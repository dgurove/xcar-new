{{-- Один пузырь: цитата ответа, текст со ссылками (Linkify), фото сеткой, время, «изменено»,
     галочки на своих (одна — ушло, две — прочитано). Удалённое — «Сообщение удалено»; сотруднику
     виден и сам текст. data-* — для склейки, дней, галочек и меню на клиенте. --}}
@php
    use App\Chats\AuthorKind;
    $deleted = $m->isDeleted();
    $own = !$readonly && !$deleted && $m->ownedBy($user);
    $reply = $m->reply_to && !$deleted ? $m->replied() : null;
    $images = $deleted ? collect() : $m->files->filter->isImage();
    $docs = $deleted ? collect() : $m->files->reject->isImage();
    // Мета (время, галочки) сидит на последней строке текста; в пузыре из одних фото — поверх фото.
    $photoOnly = !$deleted && !$m->text && $images->isNotEmpty() && $docs->isEmpty();
    $kb = fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' МБ' : max(1, (int) round($b / 1024)).' КБ';
@endphp
<div class="msg {{ $system ? 'is-system' : ($mine ? 'is-mine' : 'is-their') }} {{ $cont ? 'is-cont' : '' }} {{ $mine && !$system && $m->seq <= $readSeq ? 'is-read' : '' }} {{ $m->edited_at && !$deleted ? 'is-edited' : '' }} {{ $photoOnly ? 'is-photo' : '' }}" data-seq="{{ $m->seq }}" data-day="{{ $day }}" data-author="{{ $m->author_id ?? $m->author_kind->value }}" @if ($own) data-own @endif @if ($deleted) data-deleted @endif @if (!$system && !$readonly && !$deleted) data-action="contextmenu->chat#menu:prevent pointerdown->chat#press pointerup->chat#release pointercancel->chat#release pointermove->chat#drag" @endif>
    @if ($system)
        <div class="msg-system">{{ $m->text }}</div>
    @else
        <div class="msg-bubble" @if ($m->text && !$deleted) data-text="{{ $m->text }}" @endif>
            {{-- Имя над чужим пузырём — где на той стороне могут быть разные люди (площадка) или читает сотрудник; покупателю и его менеджеру имя говорит полоса сверху. --}}
            @if (!$cont && ($readonly || (!$mine && !$chat->isBuyerChat())))<div class="msg-author">{{ $m->authorName($chat) }}</div>@endif
            @if ($reply)
                <button type="button" class="msg-quote" data-action="chat#jump" data-seq="{{ $reply->seq }}"><span class="msg-quote-name">{{ $reply->authorName($chat) }}</span>{{ $reply->preview(80) }}</button>
            @endif
            @if ($images->isNotEmpty())
                <div class="msg-photos {{ $images->count() > 1 ? 'is-grid' : '' }}">
                    @foreach ($images as $f)<a href="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" data-action="chat#view:prevent"><img src="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" alt="" loading="lazy"></a>@endforeach
                </div>
            @endif
            @foreach ($docs as $f)
                <a href="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" target="_blank" class="msg-file"><x-ui.file-icon :name="$f->name" :mime="$f->mime"/><span class="min-w-0"><span class="block truncate text-sm">{{ $f->name }}</span>@if ($f->size)<span class="block text-xs text-ink-muted">{{ $kb($f->size) }}</span>@endif</span></a>
            @endforeach
            @if ($deleted)
                <div class="msg-deleted">Сообщение удалено</div>
                @if ($staffEyes && $m->text)<div class="msg-text text-ink-dim">{!! \App\Support\Linkify::html($m->text) !!}</div>@endif
            @elseif ($m->text)
                <div class="msg-text">{!! \App\Support\Linkify::html($m->text) !!}</div>
            @endif
            <div class="msg-meta">@if ($m->edited_at && !$deleted)<span>изменено</span>@endif<span class="nums">{{ $m->created_at->format('H:i') }}</span>@if ($mine)<x-ui.icon name="check" class="msg-tick msg-tick-sent size-3.5"/><x-ui.icon name="check-check" class="msg-tick msg-tick-read size-3.5"/>@endif</div>
        </div>
    @endif
</div>
