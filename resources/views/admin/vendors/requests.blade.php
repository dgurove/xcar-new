{{-- «Запросы» вендора в CRM (16.10.2026, владелец каждый день шлёт Т-Страхованию номера убытка с откликом; «с дизайном
     схалтурил», «историю отправок, чтобы предыдущие можно было скопировать»). Сверху «К запросу» — что ждёт отправки
     (`ClaimRequest::pendingFor`: был отклик, ещё продаётся, вендор письмом не присылал, номер не отправляли), галками;
     плашка «Отправить N номеров» — номера в буфер, запрос, Telegram (`SendClaimRequest`, `claim_request_controller`).
     Ниже «Отправленные» — запрос строкой (когда, сколько, кто), копирование кружком справа, нажатие — шторка запроса. --}}
@php
    use App\Support\Plural;
    $words = ['номер', 'номера', 'номеров'];
    $reply = function ($o) {
        $parts = [];
        if ($o->bids_count) { $parts[] = $o->bids_count."\u{00A0}".Plural::of($o->bids_count, ['подтверждение', 'подтверждения', 'подтверждений']); }
        if ($o->interests_count) { $parts[] = $o->interests_count > 1 ? $o->interests_count."\u{00A0}".Plural::of($o->interests_count, ['интерес', 'интереса', 'интересов']) : 'интерес'; }

        return implode(', ', $parts);
    };
@endphp
<div class="flex flex-col gap-2" data-controller="claim-request">
    <div class="list-head">К запросу @if ($pending->isNotEmpty())<span class="nums text-ink-muted">{{ $pending->count() }}</span>@endif</div>
    @if ($pending->isEmpty())
        <div class="list"><div class="row text-ink-muted">Новых откликов нет</div></div>
    @else
        <form id="claim-send" method="post" action="{{ $base }}/requests" class="list">
            @csrf
            @foreach ($pending as $o)
                <label class="row row-check">
                    <x-ui.cat-icon :category="$o->category()"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $o->titleWithYear() }}</span>
                        <span class="row-sub"><span class="nums">{{ $o->claim_ref }}</span><span>{{ $reply($o) }}</span><span>{{ mb_lcfirst($o->crmTab()) }}</span></span>
                    </span>
                    <span class="check"><input type="checkbox" name="offers[]" value="{{ $o->id }}" data-ref="{{ $o->claim_ref }}" checked data-claim-request-target="box" data-action="claim-request#sync"></span>
                </label>
            @endforeach
        </form>
        <x-ui.action-bar data-claim-request-target="bar">
            <button form="claim-send" class="btn btn-accent" data-action="claim-request#copy"><x-ui.icon name="send" class="size-5"/><span data-claim-request-target="label">Отправить {{ $pending->count() }} {{ Plural::of($pending->count(), $words) }}</span></button>
        </x-ui.action-bar>
    @endif

    @if ($history->isNotEmpty())
        <div class="list-head mt-4">Отправленные</div>
        <div class="list">
            @foreach ($history as $r)
                @php $n = count($r->refs); @endphp
                <div class="contents" data-controller="sheet">
                    <div class="row">
                        <button type="button" class="flex min-w-0 flex-1 flex-col text-left" data-action="sheet#open">
                            <span>{{ $r->created_at->translatedFormat($r->created_at->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}</span>
                            <span class="row-sub"><span class="nums">{{ $n }} {{ Plural::of($n, $words) }}</span>@if ($r->user)<span>{{ $r->user->shortName() }}</span>@endif</span>
                        </button>
                        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-controller="copy" data-copy-text-value="{{ $r->text() }}" data-copy-done-value="{{ $n }} {{ Plural::of($n, $words) }} в буфере" data-action="copy#copy" aria-label="Скопировать номера" title="Скопировать номера"><x-ui.icon name="copy" class="size-5"/></button>
                    </div>
                    @include('admin.vendors.request-sheet', ['r' => $r])
                </div>
            @endforeach
        </div>
    @endif
</div>
