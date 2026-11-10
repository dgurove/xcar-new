{{-- «Разобрать ответ» (03.11.2026, владелец: «пришло письмо — менеджеру вообще ничего; админ заходит в сделку, ему чётко видно,
     что пришёл такой-то ответ, и что система предлагает показать — пунктами с галками, каждый можно поправить карандашиком»).
     Ждёт разбора — плашка: «Письмо», «Не показывать», «Показать менеджеру…» и шторка (`admin.deals.reply-review-sheet`); ниже — что
     уже показано, строками. `pill` — «Показать менеджеру» пилюлей в ряду кнопок окошка, когда разбирать нечего (руками: пункты
     из машины и её документы); пока письмо ждёт — пилюли нет, есть плашка. `compact` — только плашка (окошко предложения).
     `line` (10.11.2026, начальник по Volvo 01527: лаймовое «Показать менеджеру…» сверху окошка, пока ждём ответа самого менеджера) —
     строкой в текущем шаге пути (`x-route.path`) с тихими ссылками; плашки сверху у сделки с путём больше нет. --}}
@props(['deal', 'compact' => false, 'pill' => false, 'line' => false])
@php
    use App\Offers\Actions\Disclose;
    use App\Offers\Disclosures;
    use App\Support\Ago;
    $reviews = \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->with('message.attachments')->orderByDesc('id')->get();
    $letters = $reviews->pluck('message')->filter()->values();
    $id = 'disclose-'.$deal->id;
    $offer = $deal->offer;
    $live = $deal->buyer_id && $deal->isActive();
    $shows = $live && ($pill ? $letters->isEmpty() : $letters->isNotEmpty() || ! $compact);
    $vendor = $offer->vendor?->name;
@endphp
@if ($shows)
    @php
        $proposals = Disclosures::proposals($deal, $letters);
        $step = Disclose::stepExit($deal, $letters);
        $picked = old('_sheet') === $id ? (array) old('on', []) : [];
        $shown = $compact || $pill ? collect() : Disclosures::shown($deal);
        $since = $line ? $offer->position()?->entered_at : null;
        $shown = $since ? $shown->where('created_at', '>=', $since) : $shown;
        $sheet = ['id' => $id, 'deal' => $deal, 'letters' => $letters, 'proposals' => $proposals, 'step' => $step, 'picked' => $picked];
    @endphp
    @if ($pill)
        <span class="contents" data-controller="sheet">
            <button type="button" {{ $attributes->class('pill pill-plain gap-1.5') }} data-action="sheet#open" aria-controls="{{ $id }}"><x-ui.icon name="users" class="size-4"/>Показать менеджеру</button>
            @include('admin.deals.reply-review-sheet', $sheet)
        </span>
    @elseif ($line)
        @if ($letters->isNotEmpty() || $shown->isNotEmpty())
        <div {{ $attributes->class('step-letter') }} data-controller="sheet">
            @if ($letters->isNotEmpty())
                <div class="flex items-baseline gap-2">
                    <x-ui.icon name="mail" class="size-4 shrink-0 translate-y-0.5 text-ink-muted"/>
                    <span class="min-w-0">{{ $step && \App\Offers\SupplierInvoice::needs($deal, $step) ? 'Счёт' : 'Письмо' }} от {{ $vendor ?? 'поставщика' }}{{ $letters->count() > 1 ? ', писем: '.$letters->count() : '' }} <span class="nums text-sm text-ink-muted">{!! Ago::html($letters->first()->date_at, true, words: false) !!}</span></span>
                </div>
                <div class="step-letter-acts">
                    <button type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $offer->number }}/letters?at={{ $letters->first()->id }}">Открыть</button>
                    <button type="button" data-action="sheet#open" aria-controls="{{ $id }}">Показать менеджеру</button>
                    <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" class="contents" data-turbo-confirm="Менеджер не узнает об этом письме" data-turbo-confirm-label="Не нужно"><button type="submit">Не нужно</button></form>
                </div>
                @include('admin.deals.reply-review-sheet', $sheet)
            @endif
            @foreach ($shown->take(3) as $d)
                <p class="text-sm text-ink-muted">Менеджеру показано {!! Ago::html($d->created_at, true, words: false) !!}: <span class="text-ink">{{ $d->summary() }}</span></p>
            @endforeach
        </div>
        @endif
    @elseif ($letters->isNotEmpty() || $shown->isNotEmpty())
    <div {{ $attributes }} data-controller="sheet">
        @if ($letters->isNotEmpty())
            <div class="proposal">
                <div class="proposal-body">
                    <span class="proposal-mark" aria-hidden="true"><x-ui.icon name="mail" class="size-4"/></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-ink">{{ $step && \App\Offers\SupplierInvoice::needs($deal, $step) ? 'Счёт от страховой' : 'Ответ страховой' }}{{ $letters->count() > 1 ? ', писем: '.$letters->count() : '' }}</p>
                        <p class="text-sm text-ink-muted">{{ Ago::html($letters->first()->date_at, true) }}, менеджер о нём не знает</p>
                    </div>
                </div>
                <div class="proposal-acts flex-wrap">
                    <x-mail.window-button :url="'/offers/'.$offer->number.'/letters?at='.$letters->first()->id" label="Письмо" class="btn-s"/>
                    <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" data-turbo-confirm="Менеджер не узнает об этом ответе" data-turbo-confirm-label="Ничего не показывать">
                        @csrf
                        <button class="btn btn-s btn-quiet">Не показывать</button>
                    </form>
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open" aria-controls="{{ $id }}">Показать менеджеру…</button>
                </div>
            </div>
            @include('admin.deals.reply-review-sheet', $sheet)
        @endif
        @if ($shown->isNotEmpty())
            <div @class(['flex flex-col gap-1', 'mt-3' => $letters->isNotEmpty()])>
                @foreach ($shown->take(3) as $d)
                    <p class="text-sm"><span class="text-ink-muted">Менеджеру показано {{ Ago::html($d->created_at, true, words: false) }}:</span> {{ $d->summary() }}</p>
                @endforeach
            </div>
        @endif
    </div>
    @endif
@endif
