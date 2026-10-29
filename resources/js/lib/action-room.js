// Место под плашку действий у страницы (`html.has-action` → `--action` в app.css): есть ли на экране плашка с видимой
// кнопкой — не скрытая, не плашка выбора (`--pick`), не внутри карточки строки (у листа свой подвал), на ПК не `lg:hidden`.
// Раньше — `:root:has(.action-bar …)` в CSS: с ним любая вставка узла на странице (тост, строка, подсказка) заставляла
// браузер пересчитывать стили корня — на «Все» CRM (30 тыс. узлов) десятки мс на каждую (29.10.2026). Плашек на странице
// одна-две: каждую слушает наблюдатель (кнопки появляются и прячутся — «Сохранить изменения» ждёт правки).
const lg = matchMedia('(min-width: 1024px)');
const watched = new WeakSet();
const observer = new MutationObserver(() => schedule());
let queued = false;

function shown(bar) {
    if (bar.hidden || bar.classList.contains('action-bar--pick') || bar.closest('.split-detail')) return false;
    if (lg.matches && bar.classList.contains('lg:hidden')) return false;
    const inner = bar.querySelector(':scope > .action-bar-inner');
    return !!inner && [...inner.children].some((el) => !el.hidden && el.type !== 'hidden' && el.localName !== 'template');
}

function sync() {
    queued = false;
    const bars = [...document.querySelectorAll('.action-bar')];
    for (const bar of bars) {
        if (watched.has(bar)) continue;
        watched.add(bar);
        observer.observe(bar, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden', 'type'] });
    }
    document.documentElement.classList.toggle('has-action', bars.some(shown));
}

function schedule() {
    if (queued) return;
    queued = true;
    queueMicrotask(sync);
}

export function actionRoom() {
    sync();
    for (const name of ['turbo:load', 'turbo:render', 'turbo:frame-render', 'turbo:morph']) document.addEventListener(name, schedule);
    // Поток Turbo вставляет разметку после события — проверяем следующим тактом.
    document.addEventListener('turbo:before-stream-render', () => setTimeout(schedule));
    lg.addEventListener('change', schedule);
}
