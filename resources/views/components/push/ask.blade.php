{{-- «Пришло?» после пробного пуша (push_controller#check): «Да» / «Нет»; «Нет» — где разрешить в этой системе и «Проверить
     ещё раз». Стоит внутри элемента с data-controller="push", строкой той же группы. --}}
<div class="row flex-wrap gap-2" data-push-target="ask" hidden>
    <span class="min-w-0 flex-1">Пришло пробное уведомление?</span>
    <span class="flex shrink-0 gap-2">
        <button type="button" class="btn btn-s btn-quiet !h-8 rounded-full !px-3.5" data-action="push#no">Нет</button>
        <button type="button" class="btn btn-s btn-accent !h-8 rounded-full !px-3.5" data-action="push#yes">Да</button>
    </span>
</div>
<div class="row flex-col items-stretch gap-2" data-push-target="hint" hidden>
    <span class="text-sm text-ink-muted" data-hint-text></span>
    <button type="button" class="btn btn-s btn-quiet self-start !h-8 rounded-full !px-3.5" data-action="push#check">Проверить ещё раз</button>
</div>
