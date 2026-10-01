{{-- Строка письма цепочки «Из писем»: у незаведённой ТС под заголовком стоят все её письма по времени, а не ветки
     последним письмом (иначе переписка в одной ветке схлопывалась в последний «Вопрос» и выглядела новой заявкой).
     Как `x-mail.thread-row`: кружок отправителя, кто, «Ждёт ответа», тег смысла (наше — «Мы ответили»), дата, второй
     строкой первые свои слова, скрепка. Нажатие — окно всей цепочки на этом письме. files — файлы вместе с «ч.2». --}}
@props(['message', 'url', 'waits' => false, 'files' => 0])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    $m = $message;
    $ours = $m->isOurs() && ! $m->isForwardedByStaff();
    $intent = Intent::tryFrom((string) $m->intent);
    $tag = $ours ? 'Мы ответили' : $intent?->short();
    $tone = $ours ? 'tag-dim' : ($intent === Intent::Intake ? 'tag-accent' : (in_array($intent, [Intent::Billing, Intent::Auto], true) ? 'tag-dim' : ''));
    $when = $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : ($m->date_at->isCurrentYear() ? 'j M' : 'j M Y'));
    $unread = ! $m->is_seen && ! $m->isOurs();
@endphp
<div id="letter-{{ $m->id }}" data-search-row>
    <div class="row items-center">
        <x-mail.sender-avatar :message="$m" :size="32"/>
        <button type="button" class="min-w-0 flex-1 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $url }}">
            <div class="flex items-baseline gap-2">
                <span class="min-w-0 max-w-max shrink truncate {{ $unread ? 'font-medium' : '' }}">@if ($unread)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ NodeTitle::who($m) }}</span>
                @if ($waits)<span class="tag tag-urgent shrink-0">Ждёт ответа</span>@endif
                @if ($tag)<span class="tag {{ $tone }} shrink-0">{{ $tag }}</span>@endif
                <span class="nums ml-auto shrink-0 text-xs text-ink-dim">{{ $when }}</span>
            </div>
            <div class="mt-0.5 flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate {{ $unread ? 'font-medium' : 'text-ink-muted' }}">{{ NodeTitle::words($m, 200) ?: ($files ? 'Вложения' : 'Без своих слов') }}</span>
                @if ($files)<span class="nums inline-flex shrink-0 items-center gap-0.5 text-xs text-ink-dim"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
            </div>
        </button>
    </div>
</div>
