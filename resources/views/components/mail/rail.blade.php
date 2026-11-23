{{-- Письма дела строками на линии, как в «Почте» (11.10.2026, владелец: «как цепочку писем в почте, чтобы отображалось не
     только последнее, а все — и в предложениях, и в парковке, и в сделках»): одна разметка на почту, сделку, предложение,
     дело ТС и заявку. Свежие сверху, старые снизу — как в окне писем, везде (17.11.2026, владелец: «чтобы единообразие было
     везде»); строка — x-mail.letter-row; «ч.2» того же письма склеена (файлы суммой), подряд от того же отправителя — точка
     без имени. url — окно писем на этом письме: строка-основа (`?at=` дописывается) или
     замыкание от письма. asks — письма, что ждут ответа (id или сами письма), stages — письма этапов цепочки и письмо
     заявки (id).
     fold — сколько свежих писем видно, остальные под «Ещё N писем» (страница и окно машины CRM — 3; 23.11.2026, владелец:
     «сворачивать письма в сделке до трёх последних»): прячется, только если скрытых хотя бы два; скрытое письмо ждёт ответа —
     красная точка у «Ещё». Раскрытие — `rail_fold_controller`, высота — сеткой `0fr → 1fr` (без замеров по кадрам). --}}
@props(['messages', 'url', 'asks' => [], 'stages' => [], 'fold' => null])
@php
    use App\Mail\Chains\NodeTitle;
    $letters = collect($messages)->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values();
    $continued = NodeTitle::continued($letters);
    $letters = $letters->reverse()->values();
    $files = $letters->groupBy(fn ($m) => $continued[$m->id] ?? $m->id)->map(fn ($g) => $g->sum(fn ($m) => $m->files()->count()));
    $asks = collect($asks)->map(fn ($a) => is_object($a) ? $a->id : (int) $a)->all();
    $stages = collect($stages)->filter()->map(fn ($id) => (int) $id)->all();
    $href = $url instanceof Closure ? $url : fn ($m) => $url.(str_contains($url, '?') ? '&' : '?').'at='.$m->id;
    $prev = null;
    // Письма разных ящиков в одной ленте (предложение: offer@ принимает, deal@ отвечает) — у каждого метка ящика
    // (13.10.2026, владелец: «письма только с offer и только с deal»; в «Почте» ещё переключатель ящика).
    $boxes = $letters->pluck('account_id')->unique()->count() > 1 ? once(fn () => \App\Mail\Account::query()->pluck('email', 'id')) : null;
@endphp
@php
    $rows = $letters->reject(fn ($m) => isset($continued[$m->id]))->values();
    $cut = $fold && $rows->count() > $fold + 1 ? $fold : null;
    $hiddenWaits = $cut && $rows->slice($cut)->contains(fn ($m) => in_array($m->id, $asks, true));
@endphp
<div {{ $attributes->merge(['class' => 'chain rail']) }} @if ($cut) data-controller="rail-fold" @endif>
    @foreach ($rows as $i => $m)
        @if ($cut && $i === $cut)
            <div class="rail-more" data-rail-fold-target="more" inert><div class="rail-more-in chain">
        @endif
        <x-mail.letter-row :message="$m" :url="$href($m)" :waits="in_array($m->id, $asks, true)"
            :stage="in_array($m->id, $stages, true)" :repeat="$prev && NodeTitle::email($prev) === NodeTitle::email($m)" :files="$files[$m->id] ?? 0"
            :mailbox="$boxes && isset($boxes[$m->account_id]) ? \Illuminate\Support\Str::before($boxes[$m->account_id], '@').'@' : null"/>
        @php $prev = $m; @endphp
    @endforeach
    @if ($cut)
            </div></div>
        <button type="button" class="rail-more-btn" data-rail-fold-target="button" data-action="rail-fold#toggle" aria-expanded="false">
            <span class="rail-more-shut">Ещё {{ $rows->count() - $cut }} {{ \App\Support\Plural::of($rows->count() - $cut, ['письмо', 'письма', 'писем']) }}@if ($hiddenWaits)<span class="rail-more-dot" aria-label="есть письмо без ответа"></span>@endif</span>
            <span class="rail-more-open">Свернуть</span>
            <x-ui.icon name="chevron-down" class="rail-more-chev size-4"/>
        </button>
    @endif
</div>
