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
                <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $h['url'] }}" data-copy-title-value="{{ $h['title'] }}" data-copy-done-value="Ссылка в буфере">
                    <textarea hidden data-copy-target="message">{{ $h['text'] }}</textarea>
                    <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
                    <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
                </div>
            </div>
        </x-ui.sheet>
    </div>
@endif
