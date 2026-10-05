{{-- Ответы страховой по сделке (`Offers\InsurerReplies`) перепиской: текст до подписи, как пришёл (телефон нажимается),
     под ним файлы этого письма. Внутри задачи — между тем, что сделать, и кнопками: с кем связаться, видно сразу. --}}
@php
    $replies ??= collect();
    $early ??= collect();
    $late ??= $replies;
@endphp
<section @class(['mt-5' => ! ($bare ?? false)])>
    <h3 class="text-sm font-medium text-ink-dim">{{ $replies->count() > 1 ? 'Ответы страховой' : 'Ответ страховой' }}</h3>
    <div class="mt-2 flex flex-col gap-2">
        @if ($early->isNotEmpty())
            <details class="reply-more">
                <summary class="chip">Ещё {{ $early->count() }} {{ trans_choice('ответ|ответа|ответов', $early->count()) }}</summary>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($early as $reply)@include('cabinet.deals.reply')@endforeach
                </div>
            </details>
        @endif
        @foreach ($late as $reply)@include('cabinet.deals.reply')@endforeach
    </div>
</section>
