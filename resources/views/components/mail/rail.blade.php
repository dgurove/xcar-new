{{-- Письма дела строками на линии, как в «Почте» (11.10.2026, владелец: «как цепочку писем в почте, чтобы отображалось не
     только последнее, а все — и в предложениях, и в парковке, и в сделках»): одна разметка на почту, сделку, предложение,
     дело ТС и заявку. По времени сверху вниз, строка — x-mail.letter-row; «ч.2» того же письма склеена (файлы суммой),
     подряд от того же адреса — точка без имени. url — окно писем на этом письме: строка-основа (`?at=` дописывается) или
     замыкание от письма. asks — письма, что ждут ответа (id или сами письма), stages — письма этапов цепочки и письмо
     заявки (id). --}}
@props(['messages', 'url', 'asks' => [], 'stages' => []])
@php
    use App\Mail\Chains\NodeTitle;
    $letters = collect($messages)->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values();
    $continued = NodeTitle::continued($letters);
    $files = $letters->groupBy(fn ($m) => $continued[$m->id] ?? $m->id)->map(fn ($g) => $g->sum(fn ($m) => $m->files()->count()));
    $asks = collect($asks)->map(fn ($a) => is_object($a) ? $a->id : (int) $a)->all();
    $stages = collect($stages)->filter()->map(fn ($id) => (int) $id)->all();
    $href = $url instanceof Closure ? $url : fn ($m) => $url.(str_contains($url, '?') ? '&' : '?').'at='.$m->id;
    $prev = null;
    // Письма разных ящиков в одной ленте (предложение: offer@ принимает, deal@ отвечает) — у каждого метка ящика
    // (13.10.2026, владелец: «письма только с offer и только с deal»; в «Почте» ещё переключатель ящика).
    $boxes = $letters->pluck('account_id')->unique()->count() > 1 ? once(fn () => \App\Mail\Account::query()->pluck('email', 'id')) : null;
@endphp
<div {{ $attributes->merge(['class' => 'chain rail']) }}>
    @foreach ($letters as $m)
        @continue(isset($continued[$m->id]))
        <x-mail.letter-row :message="$m" :url="$href($m)" :waits="in_array($m->id, $asks, true)"
            :stage="in_array($m->id, $stages, true)" :repeat="$prev?->from_email === $m->from_email" :files="$files[$m->id] ?? 0"
            :mailbox="$boxes && isset($boxes[$m->account_id]) ? \Illuminate\Support\Str::before($boxes[$m->account_id], '@').'@' : null"/>
        @php $prev = $m; @endphp
    @endforeach
</div>
