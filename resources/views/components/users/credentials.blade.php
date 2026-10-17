{{-- Вход для человека, заведённого руками (`AddPerson`, `NewPassword`; 18.10.2026): шторка открывается сама после
     «Добавить» / «Новый пароль», пароль — только здесь и только один раз (flash `credentials`). «Отправить» — системный
     лист текстом (WhatsApp, Telegram), «Скопировать» — тот же текст в буфер. --}}
@php $c = session('credentials'); @endphp
@if ($c)
    @php
        $text = "Вам открыт доступ к XCar\nТелефон: {$c['phone']}\nПароль: {$c['password']}\nВход:";
    @endphp
    <div class="contents" data-controller="sheet">
        <x-ui.sheet id="credentials" title="Данные для входа" open>
            <div class="flex flex-col gap-4">
                <div class="list">
                    <div class="row"><span class="shrink-0 text-ink-muted">Кто</span><span class="min-w-0 flex-1 truncate text-right">{{ $c['name'] }}</span></div>
                    <div class="row"><span class="shrink-0 text-ink-muted">Адрес</span><span class="min-w-0 flex-1 truncate text-right">{{ preg_replace('#^https?://#', '', $c['url']) }}</span></div>
                    <div class="row"><span class="shrink-0 text-ink-muted">Телефон</span><span class="min-w-0 flex-1 text-right nums">{{ $c['phone'] }}</span></div>
                    <div class="row"><span class="shrink-0 text-ink-muted">Пароль</span><span class="min-w-0 flex-1 text-right font-mono text-lg select-all">{{ $c['password'] }}</span></div>
                </div>
                <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $c['url'] }}" data-copy-title-value="Вход в XCar" data-copy-done-value="Данные для входа в буфере">
                    <textarea hidden data-copy-target="message">{{ $text }}</textarea>
                    <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
                    <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
                </div>
            </div>
        </x-ui.sheet>
    </div>
@endif
