{{-- «Напомнить через…» рядом с «Я передумал» (19.11.2026, владелец: тихая ссылка по центру под главной кнопкой — «так в
     нормальных приложениях не делают»; «справа „Напомнить мне через…“, нажимает и выбирает кол-во часов, чтобы ему снова
     уведомление и сообщение в чате и Telegram пришло»; `Workflow\Actions\RemindAsks`). Нажатие — шторка строками («1 час» …
     «Завтра в 10:00»), строка сразу сохраняет; стоит напоминание — над строками «Напомним в 18:30», внизу «Не напоминать». ask — открытый вопрос шага (`Requirement`) или доп. просьба (`ExtraAsk`, `extra`). --}}
@props(['deal', 'ask', 'extra' => false, 'back' => null])
@php
    use App\Workflow\Actions\RemindAsks;
    $at = $ask?->remind_at?->isFuture() ? $ask->remind_at : null;
    $id = 'remind-'.($extra ? 'x' : 'r').'-'.$ask?->id.'-'.substr(md5((string) $back), 0, 6);
@endphp
@if ($ask)
    <div data-controller="sheet" class="contents">
        {{-- На кнопке всегда «Напомнить через…» (19.11.2026, владелец: колокольчик со временем — «не нравится»); что стоит — в шторке.
             Ряд с «Я передумал» не делится пополам: эта кнопка берёт остаток ширины (`flex-1`), иначе на телефоне резалась. --}}
        <button type="button" {{ $attributes->class(["btn btn-quiet min-w-0 flex-1"]) }} data-action="sheet#open" data-remind><span class="truncate">Напомнить через…</span></button>
        <x-ui.sheet :id="$id" title="Напомнить через" anchor>
            @if ($at)<p class="-mt-2 mb-3 text-sm text-ink-muted">Напомним {{ RemindAsks::when($at) }}</p>@endif
            <div class="list">
                @foreach (RemindAsks::CHOICES as $when => $label)
                    <form method="post" action="/deals/{{ $deal->id }}/remind" class="contents">
                        @csrf<input type="hidden" name="when" value="{{ $when }}">
                        @if ($extra)<input type="hidden" name="extra" value="1">@endif
                        @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
                        <button class="row w-full text-left">
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                            @if ($when !== 'tomorrow')<span class="nums shrink-0 text-ink-muted">{{ RemindAsks::when(RemindAsks::at($when)) }}</span>@endif
                        </button>
                    </form>
                @endforeach
                @if ($at)
                    <form method="post" action="/deals/{{ $deal->id }}/remind" class="contents">
                        @csrf<input type="hidden" name="when" value="">
                        @if ($extra)<input type="hidden" name="extra" value="1">@endif
                        @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
                        <button class="row w-full text-left text-danger">Не напоминать</button>
                    </form>
                @endif
            </div>
        </x-ui.sheet>
    </div>
@endif
