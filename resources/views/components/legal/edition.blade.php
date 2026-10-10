{{-- Дата действующей редакции юридических текстов: под заголовком страницы и в шторке на оплате. --}}
<p {{ $attributes->class('nums text-sm font-normal text-ink-dim') }}>Редакция от {{ \Carbon\Carbon::parse('2026-09-28')->translatedFormat('j F Y') }} года</p>
