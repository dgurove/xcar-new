// Прокрутка страницы — одной дверью (22.10.2026). У экрана приложения (x-ui.shell) документ не листается: листается
// #scroller, таб-бар — блок под ним (app.css, «каркас»): прокрутка документа в iOS 26–27 таскала fixed-таб-бар до
// середины экрана. Вне шелла (вход, анкета, ошибки) листается документ. window.scrollY и scrollTo() у шелла всегда 0 —
// позицию страницы читают и ставят только отсюда.
export const scroller = () => document.getElementById('scroller') ?? document.scrollingElement ?? document.documentElement;

export const scrollTop = () => scroller().scrollTop;

export const scrollToY = (top, behavior = 'auto') => scroller().scrollTo({ top, behavior });

export const scrollByY = (dy) => scroller().scrollBy(0, dy);

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
        if (!restoring) { if (key) positions.set(key, scrollTop()); return; }
        restoring = false;
        const y = positions.get(key);
        if (y) requestAnimationFrame(() => scrollToY(y));
    });
}
