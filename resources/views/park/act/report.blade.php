{{-- Шаг «Письмо вендору»: отчёт о приёме (`x-park.report-form`) — сразу после «Принять и сформировать письмо» и по задаче
     «Отправить акт приёма»; «Дослать фото и документы» — ответом на письмо вендора (`parent`). --}}
<x-park.act-frame :title="$parent ? 'Дослать фото и документы' : 'Письмо вендору'" :vehicle="$vehicle" :self="$self">
    <x-park.report-form :vehicle="$vehicle" :advance="$advance" :parent="$parent"/>
</x-park.act-frame>
