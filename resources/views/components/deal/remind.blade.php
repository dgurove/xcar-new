{{-- «Напомнить через…» рядом с «Отказываюсь» (19.11.2026, владелец: тихая ссылка по центру под главной кнопкой — «так в
     нормальных приложениях не делают»; «справа „Напомнить мне через…“, нажимает и выбирает кол-во часов, чтобы ему снова
     уведомление и сообщение в чате и Telegram пришло»; `Workflow\Actions\RemindAsks`). Нажатие — шторка строками («1 час» …
     «6 часов», `x-deal.remind-list`), строка сразу сохраняет; стоит напоминание — над строками «Напомним в 18:30», внизу «Не напоминать». ask — открытый вопрос шага (`Requirement`) или доп. просьба (`ExtraAsk`, `extra`). --}}
@props(['deal', 'ask', 'extra' => false, 'back' => null])
@php
    $id = 'remind-'.($extra ? 'x' : 'r').'-'.$ask?->id.'-'.substr(md5((string) $back), 0, 6);
@endphp
@if ($ask)
    <div data-controller="sheet" class="contents">
        {{-- На кнопке всегда «Напомнить через…» (19.11.2026, владелец: колокольчик со временем — «не нравится»); что стоит — в шторке.
             Ряд с «Отказываюсь» не делится пополам: эта кнопка берёт остаток ширины (`flex-1`), иначе на телефоне резалась. --}}
        <button type="button" {{ $attributes->class(["btn btn-quiet min-w-0 flex-1"]) }} data-action="sheet#open" data-remind><span class="truncate">Напомнить через…</span></button>
        <x-ui.sheet :id="$id" title="Напомнить через" anchor>
            <x-deal.remind-list :deal="$deal" :ask="$ask" :extra="$extra" :back="$back"/>
        </x-ui.sheet>
    </div>
@endif
