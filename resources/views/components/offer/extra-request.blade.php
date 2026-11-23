{{-- Доп. просьбы поставщику над письмами машины (17.11.2026, владелец по CTTM Cargoline 5491/046/00861/26: «в CRM над блоком
     писем удобный блок с кнопкой»; 18.11.2026 — все просьбы машины с их ходом, `Offers\ExtraAsk`): группа строк, как «Настройки» iOS —
     что попросили, под ней серым когда; справа одно слово шага: «ждём ответа 2ч», «показать менеджеру» зелёным (наш ход), «ждём
     менеджера», «ответили 18.11 ›» (окно на ответе), «менеджер берёт». Кнопки — своим рядом под группой (строки без кнопок справа):
     «Сделать доп. просьбу» (окно писем с галками, `MailController::compose` с `extra`) и у последней отвеченной «Вопрос не решён»
     (владелец: ответ «отсутствую на рабочем месте» — снова ждём первого ответа). --}}
@props(['offer', 'messages' => [], 'compose' => true])
@php
    use App\Offers\ExtraAsk;
    use App\Support\Ago;
    $asks = ExtraAsk::where('offer_id', $offer->id)->orderByDesc('id')->get();
    $n = $offer->number;
    $last = $asks->first();
    $reopen = $last && $last->answered_at && in_array($last->decision, [null, ExtraAsk::ANSWERED], true) && auth()->user()?->canEditDeals();
    $letter = fn (int $id) => '/offers/'.$n.'/letters?at='.$id;
@endphp
<div {{ $attributes->class('extra-request') }}>
    @if ($asks->isNotEmpty())
        <section>
            <h3 class="extra-request-title px-1">Доп. просьбы</h3>
            <div class="list mt-2">
                @foreach ($asks as $ask)
                    @php $phase = $ask->phase(); @endphp
                    <div class="row">
                        <span class="min-w-0 flex-1">
                            <span class="block">{{ \Illuminate\Support\Str::ucfirst($ask->phrase()) }}</span>
                            {{-- Когда и где сейчас — одной строкой под просьбой: справа места на телефоне нет, просьба длинная. --}}
                            <span class="row-sub nums">
                                @if ($ask->message_id)
                                    <button type="button" class="text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letter($ask->message_id) }}">письмо {!! Ago::html($ask->asked_at, true, words: false) !!} ›</button>
                                @else
                                    <span>{!! Ago::html($ask->asked_at, true, words: false) !!}</span>
                                @endif
                                @if ($phase === ExtraAsk::SUPPLIER)
                                    <span>ждём ответа {{ Ago::since($ask->since_at, hours: true) }}</span>
                                @elseif ($phase === ExtraAsk::SHOW)
                                    <span class="text-accent-text">показать менеджеру</span>
                                @elseif ($phase === ExtraAsk::MANAGER)
                                    <span>ждём решения менеджера</span>
                                @elseif ($ask->decision === ExtraAsk::TAKE)
                                    <span>менеджер берёт</span>
                                @elseif ($ask->decision === ExtraAsk::DROP)
                                    <span class="text-danger">менеджер отказался</span>
                                @elseif ($ask->answer_message_id)
                                    <button type="button" class="text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letter($ask->answer_message_id) }}">ответили {!! Ago::html($ask->answered_at, true, words: false) !!} ›</button>
                                @else
                                    <span>ответили не письмом</span>
                                @endif
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
    {{-- compose=false — «Доп. просьба» стоит кнопкой в ряду карточки писем (страница машины, 23.11.2026). --}}
    @if ($compose || $reopen)
    <div class="flex flex-wrap gap-2">
        @if ($compose)<x-mail.window-button :url="'/work/mail/new?offer='.$offer->number.'&extra=1'" label="Сделать доп. просьбу" icon="mail" class="btn-s"/>@endif
        @if ($reopen)
            <form method="post" action="/offers/{{ $n }}/extra-asks/{{ $last->id }}/reopen" class="contents">@csrf<x-ui.button size="sm" variant="secondary">Вопрос не решён</x-ui.button></form>
        @endif
    </div>
    @endif
</div>
