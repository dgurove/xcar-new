// Прокрутка страницы — одной дверью (22.10.2026). У экрана приложения (x-ui.shell) документ не листается: листается
// #scroller, таб-бар — блок под ним (app.css, «каркас»): прокрутка документа в iOS 26–27 таскала fixed-таб-бар до
// середины экрана. Вне шелла (вход, анкета, ошибки) листается документ. window.scrollY и scrollTo() у шелла всегда 0 —
// позицию страницы читают и ставят только отсюда.
export const scroller = () => document.getElementById('scroller') ?? document.scrollingElement ?? document.documentElement;

export const scrollTop = () => scroller().scrollTop;

// Прокрутка страницы без чтения раскладки: число из последнего события прокрутки (18.11.2026). `scrollTop` у страницы,
// где только что что-то поменяли (класс нажатой плитки, подмена body), заставляет браузер тут же пересчитать стили и
// раскладку — на уходе со списка это 40 мс на телефоне. Новая страница без единого события стоит сверху.
const seen = { el: null, y: 0 };
addEventListener('scroll', (e) => { if (e.target === scroller()) { seen.el = e.target; seen.y = e.target.scrollTop; } }, { capture: true, passive: true });
export const knownY = () => (seen.el === scroller() ? seen.y : 0);

export const scrollToY = (top, behavior = 'auto') => scroller().scrollTo({ top, behavior });

export const scrollByY = (dy) => scroller().scrollBy(0, dy);

// Место после возврата из снимка держится, пока раскладка дорастает (18.11.2026, «Предложения» менеджера: ушёл табом,
// вернулся — список на строку ниже). У свежего DOM куски за экраном (`content-visibility: auto` — ленты, плитки, порции
// таблицы) стоят в запасную высоту: прокрутка встаёт по ней, в первом же кадре кусок получает настоящую, и браузер
// «якорем» сдвигает прокрутку вслед — место уезжает на разницу. Пока человек не тронул экран (и не дольше `ms`),
// прокрутка возвращается на своё число после каждой смены высоты страницы — в том же кадре, до отрисовки.
let held = null;
const TOUCHED = ['pointerdown', 'touchstart', 'wheel', 'keydown'];
export function holdY(y, ms = 1500) {
    held?.();
    const el = scroller();
    scrollToY(y);
    seen.el = el; seen.y = y;
    const content = el.firstElementChild;
    if (!content || typeof ResizeObserver === 'undefined') return;
    const watch = new ResizeObserver(() => { if (Math.abs(el.scrollTop - y) > 1) el.scrollTo({ top: y, behavior: 'auto' }); });
    const stop = () => {
        if (held !== stop) return;
        held = null;
        watch.disconnect();
        clearTimeout(timer);
        for (const name of TOUCHED) removeEventListener(name, stop, { capture: true });
    };
    const timer = setTimeout(stop, ms);
    for (const name of TOUCHED) addEventListener(name, stop, { capture: true, passive: true });
    held = stop;
    watch.observe(content);
}

// Видимая часть страницы: под шапкой и над видимой плашкой действий (у fixed-узла offsetParent всегда null, а спрятанная
// клавиатурой плашка коробку сохраняет — смотрим коробки и видимость). bottom — не ниже переданного края (клавиатура).
export const pageEdges = (bottom = innerHeight) => {
    // Шапка — над #scroller, его верх и есть её низ.
    const box = scroller().getBoundingClientRect();
    const top = Math.max(box.top, 0);
    let end = Math.min(bottom, box.bottom || bottom);
    const bar = [...document.querySelectorAll('.action-bar')].find((b) => b.getClientRects().length && getComputedStyle(b).visibility !== 'hidden');
    if (bar) end = Math.min(end, bar.getBoundingClientRect().top);
    return { top, bottom: end };
};

// scroll не всплывает: на window его слышно только перехватом, вместе с прокрутками шторок, лент и таблиц — берётся
// только сама страница.
export const onScroll = (fn) => {
    const handler = (e) => { if (e.target === document || e.target === scroller()) fn(e); };
    addEventListener('scroll', handler, { capture: true, passive: true });
    return () => removeEventListener('scroll', handler, { capture: true });
};

// «Назад» возвращает место. Turbo помнит прокрутку окна, а у шелла она всегда 0: позиция пишется по записи истории
// (restorationIdentifier) той страницы, что на экране, и ставится на turbo:load визита restore. Запись — своя у страницы с
// её загрузки: Turbo меняет history.state ещё при старой странице на экране, и её инерция легла бы в чужую запись. Новый
// визит приносит новый #scroller — он и так сверху; морф (живое обновление, refresh) узел оставляет, позиция остаётся.
export function keepPosition() {
    const positions = new Map();
    const current = () => history.state?.turbo?.restorationIdentifier;
    let key = current();
    let restoring = false;
    let frame = 0;
    onScroll(() => {
        if (frame) return;
        frame = requestAnimationFrame(() => { frame = 0; if (key) positions.set(key, scrollTop()); });
    });
    document.addEventListener('turbo:visit', (e) => { restoring = e.detail.action === 'restore'; });
    document.addEventListener('turbo:load', () => {
        key = current();
        // Живое обновление (refresh — визит replace с новой записью) узел оставляет на месте: прокрутка та же, а запись
        // новая — без этого «Назад» после него возвращал в начало списка.
        if (!restoring) { if (key) positions.set(key, knownY()); return; }
        restoring = false;
        const y = positions.get(key);
        if (y) requestAnimationFrame(() => holdY(y));
    });
}

// Пока страница едет — без наведения (31.10.2026, замер «Выданных» колесом: под неподвижным курсором проезжающие строки и
// плитки включали `:hover`, увеличение фото и стрелки — пересчёт стилей, hit-test и отрисовка на каждый кадр). Класс на
// #scroller, его детям `pointer-events: none` (наследуется — браузер не пересчитывает поддерево); через 150 мс после
// последнего шага — снова как было. Только у мыши: на касаниях наведения нет.
export function quietHover() {
    if (!matchMedia('(hover: hover)').matches) return;
    let timer = 0;
    let el = null;
    onScroll(() => {
        if (!timer) (el = scroller()).classList.add('is-scrolling');
        clearTimeout(timer);
        timer = setTimeout(() => { timer = 0; el?.classList.remove('is-scrolling'); }, 150);
    });
}
