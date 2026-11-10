{{-- Вопрос перед черновиком письма шага (10.11.2026, начальник: «не сразу окно черновика, а спрашивало — писать, закрыть шаг
     без письма или отмена»). Открывается в окне писем вместо редактора (`LetterPrompt::ask`): «Написать письмо» — редактор в
     том же окне, «Без письма — сообщили сами» — `SkipLetter`, «Отмена» — окно закрывается, письмо ждёт на шаге. --}}
@php $skippable = in_array($position?->letterStatus(), ['unsent', 'failed', 'bounced'], true); @endphp
<turbo-frame id="letters-frame" target="_top">
    <template data-window-title>Письмо поставщику</template>
    <div class="flex flex-col gap-5" data-window-compact>
        <div>
            <p class="text-lg font-medium">{{ $position?->stage->name ?? 'Письмо поставщику' }}</p>
            <p class="text-sm text-ink-muted">{{ $offer->titleWithYear() }}, {{ $offer->vendor?->name }}</p>
        </div>
        <div class="flex flex-col gap-2">
            <a href="{{ $to }}" data-turbo-frame="letters-frame" class="btn btn-accent w-full"><x-ui.icon name="send" class="size-4"/>Написать письмо</a>
            @if ($skippable)
                <form method="post" action="/offers/{{ $offer->number }}/letter-skip" data-turbo-frame="letters-frame">
                    @csrf<button type="submit" class="btn btn-quiet w-full">Без письма — сообщили сами</button>
                </form>
            @endif
            <button type="button" class="btn btn-ghost w-full" data-action="window#close">Отмена</button>
        </div>
    </div>
</turbo-frame>
