{{-- Один пузырь переписки бота. Исходящее — HTML бота как ушёл (жирный заголовок, VIN кодом), под ним
     inline-кнопки рядами, как в Telegram; написанное из CRM — с именем сотрудника; не дошло — причина красным.
     Входящее — текст через Linkify, фото и файлы — с Telegram через /settings/telegram/files/{id}.
     Нажатие кнопки и «запустил / остановил бота» — строкой по центру. --}}
@php
    $mine = $m->isOut();
    $deleted = $m->deleted_at !== null;
    $file = $m->hasFile() && !$deleted ? '/settings/telegram/files/'.$m->id : null;
    $image = $file && $m->isImage();
    $plain = $mine ? $m->plain() : (string) $m->text;
    $photoOnly = $image && trim($plain) === '' && !$m->keyboard;
    $reply = $m->replied && !$deleted ? $m->replied : null;
    $kb = fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' МБ' : max(1, (int) round($b / 1024)).' КБ';
@endphp
@if ($m->isEvent())
    <div class="msg is-system" data-seq="{{ $m->id }}" data-day="{{ $day }}" data-author="event">
        <div class="msg-system">{{ $m->preview(120) }} в {{ $m->created_at->format('H:i') }}</div>
    </div>
@else
<div class="msg {{ $mine ? 'is-mine' : 'is-their' }} {{ $cont ? 'is-cont' : '' }} {{ $m->edited_at && !$deleted ? 'is-edited' : '' }} {{ $photoOnly ? 'is-photo' : '' }}" data-seq="{{ $m->id }}" data-day="{{ $day }}" data-author="{{ $who }}" @if ($m->isManual()) data-own @endif @if ($deleted) data-deleted @endif @unless ($deleted) data-action="contextmenu->chat#menu:prevent pointerdown->chat#press pointerup->chat#release pointercancel->chat#release pointermove->chat#drag" @endunless>
    <div class="msg-stack">
        <div class="msg-bubble" @if (trim($plain) !== '' && !$deleted) data-text="{{ $plain }}" @endif>
            @if ($m->author && !$cont)<div class="msg-author">{{ $m->author->shortName() }}</div>@endif
            @if ($reply)
                <button type="button" class="msg-quote" data-action="chat#jump" data-seq="{{ $reply->id }}"><span class="msg-quote-name">{{ $reply->isOut() ? 'Бот' : $chat->displayName() }}</span>{{ $reply->preview(80) }}</button>
            @endif
            @if ($image)
                <div class="msg-photos"><a href="{{ $file }}" data-action="chat#view:prevent"><img src="{{ $file }}" alt="" loading="lazy"></a></div>
            @elseif ($file && $m->kind === 'voice')
                <audio class="msg-audio" controls preload="none" src="{{ $file }}"></audio>
            @elseif ($file)
                <a href="{{ $file }}" target="_blank" class="msg-file"><x-ui.file-icon :name="$m->file_name ?? 'file'" :mime="$m->file_mime"/><span class="min-w-0"><span class="block truncate text-sm">{{ $m->file_name ?? 'Файл' }}</span>@if ($m->file_size)<span class="block text-xs text-ink-muted">{{ $kb($m->file_size) }}</span>@endif</span></a>
            @elseif ($m->kind === 'sticker' || $m->kind === 'voice')
                <div class="msg-text">{{ $m->preview() }}</div>
            @endif
            @if ($deleted)
                <div class="msg-deleted">Сообщение удалено</div>
            @elseif (trim($plain) !== '' && $m->kind !== 'sticker')
                <div class="msg-text">{!! $mine ? $m->html() : \App\Support\Linkify::html($m->text) !!}</div>
            @endif
            <div class="msg-meta">@if ($m->edited_at && !$deleted)<span>изменено</span>@endif<span class="nums">{{ $m->created_at->format('H:i') }}</span>@if ($mine && !$m->failed)<x-ui.icon name="check" class="msg-tick msg-tick-sent size-3.5"/>@endif</div>
        </div>
        @if ($m->keyboard && !$deleted)
            <div class="msg-keys">
                @foreach ($m->keyboard as $row)
                    <div class="msg-keys-row">
                        @foreach ($row as $key)
                            @if (isset($key['url']))<a href="{{ $key['url'] }}" target="_blank" rel="noopener" class="msg-key">{{ $key['text'] }}<x-ui.icon name="link" class="size-3 opacity-70"/></a>@else<span class="msg-key">{{ $key['text'] }}</span>@endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
        @if ($m->failed)<div class="msg-failed">Не доставлено: {{ $m->failedLabel() }}</div>@endif
    </div>
</div>
@endif
