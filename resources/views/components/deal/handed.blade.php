{{-- Ссылка тому, кому отдали машину (28.10.2026, владелец: «чтобы формировалась сразу ссылка на страницу сделки — я ему
     отправлю, и он сразу откроет»): после «Отдать» (`GiveController::done`, flash `handed`) — шторка с адресом его сделки
     или машины в гараже (`Deal::personUrl`), «Отправить» текстом и «Скопировать». Стоит в оболочке (`#handed-host`), из
     ответа карточки строки приезжает потоком (`x-ui.detail-streams`). Открылось ещё и окно письма поставщику — шторка
     ждёт, пока его закроют (`handed_controller`). --}}
@php $h = session('handed'); @endphp
@if ($h)
    <div class="contents" data-controller="sheet handed" @if ($h['wait'] ?? false) data-handed-wait-value="true" @endif>
        <x-ui.sheet id="handed" :title="'Отдано: '.$h['name']">
            <div class="flex flex-col gap-4">
                <div class="list">
                    <div class="row"><span class="shrink-0 text-ink-muted">ТС</span><span class="min-w-0 flex-1 truncate text-right">{{ $h['title'] }}</span></div>
                    <div class="row"><span class="shrink-0 text-ink-muted">Ссылка</span><span class="min-w-0 flex-1 truncate text-right">{{ preg_replace('#^https?://#', '', $h['url']) }}</span></div>
                </div>
                <x-ui.share-link :url="$h['url']" :title="$h['title']" done="Ссылка в буфере" :text="$h['text']"/>
            </div>
        </x-ui.sheet>
    </div>
@endif
