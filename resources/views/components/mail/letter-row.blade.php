{{-- Письмо цепочки «Из писем» — узел той же ленты, что в окне писем (`.chain` / `.letter`), только свёрнутый и кнопкой:
     кружок отправителя 24 px на линии, кто, первые свои слова, скрепка, справа перед
     датой — тег значимого смысла (`Intent::marked`, у нашего письма тега нет) или «Ждёт ответа». На ПК одна строка, на телефоне две.
     repeat — то же письмо от того же адреса подряд: точка вместо кружка и без имени, как сообщения подряд в мессенджере.
     Нажатие — окно всей цепочки на этом письме. files — файлы вместе с «ч.2». --}}
@props(['message', 'url', 'waits' => false, 'stage' => false, 'repeat' => false, 'files' => 0])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    $m = $message;
    $ours = $m->isOurs() && ! $m->isForwardedByStaff();
    $intent = Intent::tryFrom((string) $m->intent);
    $tag = $ours ? null : $intent?->marked();
    $when = $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : ($m->date_at->isCurrentYear() ? 'j M' : 'j M Y'));
    $kind = trim(($stage ? 'stage ' : ($waits ? 'ask ' : '')).($ours ? 'ours ' : '').($repeat ? 'repeat ' : '').(! $m->is_seen && ! $m->isOurs() ? 'unread' : ''));
    $words = NodeTitle::words($m, 200) ?: ($files ? 'Вложения' : 'Без своих слов');
@endphp
<div class="letter{{ $kind ? ' '.implode(' ', array_map(fn ($k) => 'letter--'.$k, explode(' ', $kind))) : '' }}" id="letter-{{ $m->id }}" data-search-row>
    @if ($repeat)<span class="letter-dot"></span>@else<span class="letter-face"><x-mail.sender-avatar :message="$m" :size="24"/></span>@endif
    <div class="letter-body">
        <button type="button" class="letter-head w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $url }}">
            @unless ($repeat)
                <span class="letter-who">{{ NodeTitle::who($m) }}</span>
            @endunless
            <span class="letter-title">{{ $words }}</span>
            @if ($files)<span class="letter-clip nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
            {{-- Подпись и дата — одним куском через пробел. --}}
            <span class="letter-meta">@if ($waits)<span class="letter-tag tag tag-urgent">Ждёт ответа</span> @elseif ($tag)<span class="letter-tag tag {{ $intent === Intent::Intake ? 'tag-accent' : '' }}">{{ $tag }}</span> @endif<span class="letter-when nums">{{ $when }}</span></span>
        </button>
    </div>
</div>
