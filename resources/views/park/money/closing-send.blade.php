{{-- «Отправить» документ месяца — сначала вопрос (09.11.2026, владелец: «спрашивал бы, что отправить: счёт и акт, только
     счёт или только акт»; «это на согласование или финал»): на сверку — письмо текстом сверки, документ остаётся живым
     (номер и суммы не закрепляются); финальный — отправка фиксирует его, как раньше. Ответ — окно письма с нужными
     вложениями и шаблоном (`MailController::compose`, `?send=` и `?review=1`, `Mail\Template::SENDS`). --}}
@php
    $id = 'send-'.md5($mailUrl.$label);
    $url = fn (string $send, bool $review = false) => $mailUrl.'&'.http_build_query(array_filter(['send' => $send, 'review' => $review ? 1 : null]));
    $options = [
        'На сверку' => [['act', true, 'Только акт'], ['both', true, 'Счёт и акт'], ['invoice', true, 'Только счёт']],
        'Финальный' => [['both', false, 'Счёт и акт'], ['act', false, 'Только акт'], ['invoice', false, 'Только счёт']],
    ];
@endphp
<span data-controller="sheet" class="contents">
    <button type="button" class="{{ $class }}" data-action="sheet#open"><x-ui.icon name="send" class="size-4"/>{{ $label }}</button>
    <x-ui.sheet :id="$id" title="Что отправить">
        <div class="flex flex-col gap-4">
            @foreach ($options as $head => $rows)
                <div>
                    <div class="list-head">{{ $head }}</div>
                    <div class="list">
                        @foreach ($rows as [$send, $review, $text])
                            <button type="button" class="row w-full justify-between text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $url($send, $review) }}">
                                <span>{{ $text }}</span><x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.sheet>
</span>
