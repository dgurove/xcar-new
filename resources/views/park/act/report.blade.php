{{-- Шаг «Письмо вендору»: отчёт о приёме (`x-park.report-form`) — сразу после «Принять и сформировать письмо» и по задаче
     «Отправить акт приёма». --}}
<x-park.act-frame title="Письмо вендору" :vehicle="$vehicle" :self="$self">
    <x-park.report-form :vehicle="$vehicle" :advance="$advance"/>
</x-park.act-frame>
