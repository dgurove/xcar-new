{{-- Один пузырь: цитата ответа, текст со ссылками (Linkify), фото сеткой, время, «изменено»,
     галочки на своих (одна — ушло, две — прочитано). Удалённое — «Сообщение удалено»; сотруднику
     виден и сам текст. data-* — для склейки, дней, галочек и меню на клиенте. --}}
@php
    use App\Chats\AuthorKind;
    $deleted = $m->isDeleted();
    $own = !$readonly && !$deleted && $m->ownedBy($user);
    // Удалить: своё — автору, любое в чате площадки — админу (17.11.2026, `Message::deletableBy`).
    $del = !$readonly && !$deleted && !$system && $m->deletableBy($user, $chat);
    $reply = $m->reply_to && !$deleted ? $m->replied() : null;
    $images = $deleted ? collect() : $m->files->filter->isImage();
    // Удалённое видят только сотрудники — файлы строками, без миниатюр.
    $docs = $deleted ? $m->files : $m->files->reject->isImage();
    // Мета (время, галочки) сидит на последней строке текста; в пузыре из одних фото — поверх фото.
    $photoOnly = !$deleted && !$m->text && $images->isNotEmpty() && $docs->isEmpty();
    // Под пузырём — кнопки шага (сообщение площадки с просьбой) или «Это чек об оплате» (файлы менеджера, шаг ждёт документ).
    // `ask` — вопрос площадки без просьбы шага (11.11.2026: «Когда покупатель сможет подписать?» — `x-chat.signing-keys`).
    $stepKeys = !$deleted && !$system && ($m->requirement_id || $m->ask);
    $docPick = !$deleted && !$stepKeys && ($docAsk ?? null) && $m->author_kind === AuthorKind::Participant && $m->files->isNotEmpty()
        && $m->created_at->gte($docAsk->created_at->copy()->subDays(2));
    $author = $system || $cont ? null : $m->authorName($chat, $user);
    $authorShown = $author !== null && ($readonly || (!$mine && !$chat->isBuyerChat() && $author !== $chat->counterpartName($user)));
@endphp
<div class="msg {{ $system ? 'is-system' : ($mine ? 'is-mine' : 'is-their') }} {{ $cont ? 'is-cont' : '' }} {{ $mine && !$system && $m->seq <= $readSeq ? 'is-read' : '' }} {{ $m->edited_at && !$deleted ? 'is-edited' : '' }} {{ $photoOnly ? 'is-photo' : '' }} {{ $docs->isNotEmpty() ? 'has-file' : '' }} {{ $stepKeys || $docPick ? 'has-step' : '' }}" data-seq="{{ $m->seq }}" data-day="{{ $day }}" data-author="{{ $m->author_id ?? $m->author_kind->value }}" @if ($own) data-own @endif @if ($del) data-del @endif @if ($deleted) data-deleted @endif @if (!$system && !$readonly && !$deleted) data-action="contextmenu->chat#menu:prevent pointerdown->chat#press pointerup->chat#release pointercancel->chat#release pointermove->chat#drag" @endif>
    @if ($system)
        <div class="msg-system">{{ $m->text }}</div>
    @else
        <div class="msg-bubble" @if ($m->text && !$deleted) data-text="{{ $m->text }}" @endif>
            {{-- Меню пузыря на ПК — кружок «⌄» в углу при наведении (17.11.2026, владелец: удаление «красиво, удобно, ненавязчиво»;
                 правую кнопку никто не знал). Телефон — долгое нажатие. --}}
            @if (!$readonly && !$deleted)<button type="button" class="msg-more" data-action="chat#more" aria-label="Действия с сообщением"><x-ui.icon name="chevron-down" class="size-4"/></button>@endif
            {{-- Имя над чужим пузырём — где на той стороне могут быть разные люди (площадка) или читает сотрудник; покупателю и его менеджеру имя говорит полоса сверху. --}}
            {{-- То же имя, что в шапке чата, не повторяем (20.11.2026, владелец: «зачем имя писать, если сверху имя написано»): у
                 пузыря оно — только когда на той стороне пишет не тот, кто в шапке. --}}
            @if ($authorShown)<div class="msg-author">{{ $author }}</div>@endif
            @if ($reply)
                <button type="button" class="msg-quote" data-action="chat#jump" data-seq="{{ $reply->seq }}"><span class="msg-quote-name">{{ $reply->authorName($chat, $user) }}</span>{{ $reply->preview(80) }}</button>
            @endif
            @if ($images->isNotEmpty())
                <div class="msg-photos {{ $images->count() > 1 ? 'is-grid' : '' }}">
                    @foreach ($images as $f)<a href="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" data-action="chat#view:prevent"><img src="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" data-thumb="/chats/{{ $m->chat_id }}/files/{{ $f->id }}?thumb=1" alt="" loading="lazy" decoding="async"></a>@endforeach
                </div>
            @endif
            {{-- Файл — как в Telegram (25.11.2026, владелец прислал снимок): значок слева, имя, под ним «вес - Скачать» (дефис — как у Telegram, исключение владельца); значок и имя
                 открывают шторку документов, «Скачать» (`download`, шторка такие не ловит) — сам файл, и PDF тоже. --}}
            @foreach ($docs as $f)
                @php $href = '/chats/'.$m->chat_id.'/files/'.$f->id; @endphp
                <div class="msg-file">
                    <a href="{{ $href }}" target="_blank" data-doc="{{ \App\Support\Docs::type($f->mime, $f->name) }}" data-doc-name="{{ \App\Support\Docs::label($f->name) }}" title="{{ $f->name }}" class="msg-file-open">
                        <x-ui.file-icon :name="$f->name" :mime="$f->mime"/>
                        <span class="msg-file-name"><x-ui.file-name :name="$f->name"/></span>
                    </a>
                    <div class="msg-file-sub"><span class="nums">{{ \App\Support\FileSize::short($f->size) }}</span>@if (!$deleted)<span aria-hidden="true">-</span><a href="{{ $href }}" download="{{ $f->name }}" class="msg-file-get">Скачать</a>@endif</div>
                </div>
            @endforeach
            @if ($deleted)
                {{-- Сюда доходят только сотрудники (собеседнику удалённого нет, `Message::hiddenFor`): что было — серым, кто и когда удалил. --}}
                @if ($m->text)<div class="msg-text">{!! \App\Support\Linkify::html($m->text) !!}</div>@endif
                <div class="msg-deleted">Удалено@if ($m->deletedBy): {{ $m->deletedBy->is($user) ? 'вами' : $m->deletedBy->shortName() }}@endif, <span class="nums">{!! \App\Support\Ago::html($m->deleted_at, true, words: false) !!}</span></div>
            @elseif ($m->text)
                <div class="msg-text">{!! \App\Support\Linkify::html($m->text) !!}</div>
            @endif
            <div class="msg-meta">@if ($m->edited_at && !$deleted)<span>изменено</span>@endif<span class="nums">{{ $m->created_at->format('H:i') }}</span>@if ($mine)<x-ui.icon name="check" class="msg-tick msg-tick-sent size-3.5"/><x-ui.icon name="check-check" class="msg-tick msg-tick-read size-3.5"/>@endif</div>
        </div>
        {{-- Кнопки шага под сообщением площадки (`x-chat.step-keys`) и «Это чек об оплате» под файлами менеджера. --}}
        @if ($stepKeys)
            @if ($m->ask === 'signing')
                <x-chat.signing-keys :chat="$chat" :user="$user" :readonly="$readonly"/>
            @else
                <x-chat.step-keys :message="$m" :chat="$chat" :user="$user" :readonly="$readonly"/>
            @endif
        @elseif ($docPick)
            <x-chat.doc-pick :message="$m" :ask="$docAsk" :user="$user" :chat="$chat"/>
        @endif
    @endif
</div>
