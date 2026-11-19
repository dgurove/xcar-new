{{-- «Напомнить через…» рядом с «Я передумал» (19.11.2026, владелец: тихая ссылка по центру под главной кнопкой — «так в
     нормальных приложениях не делают»; «справа „Напомнить мне через…“, нажимает и выбирает кол-во часов, чтобы ему снова
     уведомление и сообщение в чате и Telegram пришло»; `Workflow\Actions\RemindAsks`). Кнопка той же ширины, что «Я передумал»;
     нажатие — шторка строками («1 час» … «Завтра в 10:00»), строка сразу сохраняет; стоит напоминание — на кнопке «Напомним в
     18:30», внизу шторки «Не напоминать». ask — открытый вопрос шага (`Requirement`) или доп. просьба (`ExtraAsk`, `extra`). --}}
@props(['deal', 'ask', 'extra' => false, 'back' => null])
@php
    use App\Workflow\Actions\RemindAsks;
    $at = $ask?->remind_at?->isFuture() ? $ask->remind_at : null;
    $id = 'remind-'.($extra ? 'x' : 'r').'-'.$ask?->id.'-'.substr(md5((string) $back), 0, 6);
@endphp
@if ($ask)
    <div data-controller="sheet" class="contents">
        <button type="button" {{ $attributes->class(["btn btn-quiet min-w-0"]) }} data-action="sheet#open" data-remind>
            {{-- На половине ширины телефона длинное резалось («Напомнить че…»): «Напомнить…», стоит — колокольчик и время. --}}
            @if ($at)<x-ui.icon name="bell" class="size-[18px] shrink-0"/><span class="truncate">{{ RemindAsks::when($at, short: true) }}</span>@else<span class="truncate">Напомнить…</span>@endif
        </button>
        <x-ui.sheet :id="$id" title="Напомнить через" anchor>
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
