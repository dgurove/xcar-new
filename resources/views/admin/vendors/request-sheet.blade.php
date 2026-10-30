{{-- Шторка запроса номеров вендору (16.10.2026): сверху «Скопировать» и «В Telegram» круглыми кнопками, как карточка ссылки
     «Оплат»; ниже номера снимка строками — ТС, номер и где машина сейчас словом вкладки (видно, что из запроса ушло в
     сделку). Вернуть номер в «К запросу» — кружок справа с подтверждением; вернули — серое «возвращён», в снимке он
     остаётся. Открывается и сразу — `?pill=requests&open={id}` из столбца «Запрос» в «Предложениях». --}}
@php $n = count($r->refs); $words = ['номер', 'номера', 'номеров']; @endphp
<x-ui.sheet :id="'claim-request-'.$r->id" :title="'Запрос '.\App\Support\Ago::day($r->created_at, true)" :open="$open === $r->id">
    <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $r->text() }}" data-copy-done-value="{{ $n }} {{ \App\Support\Plural::of($n, $words) }} в буфере">
        <button type="button" class="act" data-action="copy#copy"><span class="btn btn-accent btn-round"><x-ui.icon name="copy"/></span>Скопировать</button>
        <form method="post" action="{{ $base }}/requests/{{ $r->id }}/telegram" class="contents">
            @csrf<button class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="send"/></span>В Telegram</button>
        </form>
    </div>
    <div class="list mt-6">
        @foreach ($r->refs as $ref)
            @php $o = $sent[$ref] ?? null; $here = $o && $o->claim_request_id === $r->id; @endphp
            <div class="row">
                <span class="min-w-0 flex-1">
                    @if ($o)<a href="/offers/{{ $o->number }}" class="block truncate">{{ $o->titleWithYear() }}</a>@endif
                    <span class="row-sub"><span class="nums">{{ $ref }}</span>@if ($o)<span>{{ $here ? mb_lcfirst($o->crmTab()) : 'возвращён в «К запросу»' }}</span>@endif</span>
                </span>
                @if ($here)
                    <form method="post" action="{{ $base }}/offers/{{ $o->number }}/request" class="shrink-0" data-turbo-confirm="Вернуть {{ $ref }} в «К запросу»?" data-turbo-confirm-label="Вернуть">
                        @csrf @method('delete')<button class="btn btn-s btn-quiet btn-round" aria-label="Вернуть в «К запросу»" title="Вернуть в «К запросу»"><x-ui.icon name="undo" class="size-4"/></button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</x-ui.sheet>
