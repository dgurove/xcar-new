// Шторка на <dialog>, общее для sheet_controller, share_controller и confirm:
// открытие — выезд снизу (CSS sheet-up), уход — класс is-closing с анимацией
// вниз и close() по её концу; свайп вниз за ручку, шапку или тело у верха
// прокрутки — лист едет за пальцем, вверх — с сопротивлением, отпущенный
// быстро или ниже трети — падает, иначе возвращается пружиной.
// Открытая шторка — запись в истории: свайп от края и «Назад» закрывают её, а
// не страницу. Чтобы Turbo не делал restore-визит на этот popstate, у записи
// страницы на время шторки снимается ключ turbo (пустое состояние Turbo лишь
// переписывает, не перерисовывая); закрытие крестиком само снимает запись
// через history.back().
export const reduce = matchMedia('(prefers-reduced-motion: reduce)');
let inHistory = null; // id шторки, чья запись сверху истории
let settle = null;    // ждёт popstate после history.back() из closeSheet

window.addEventListener('popstate', (event) => {
    const forward = event.state?.sheet && event.state.sheet !== inHistory;
    if (!inHistory && !forward) return;
    if (forward) { history.back(); return; }
    const d = document.getElementById(inHistory);
    inHistory = null;
    if (d?.open) closeSheet(d, 0, true);
    settle?.();
    settle = null;
});
// Визит уводит со страницы (или заменяет запись) — верхняя запись больше не шторка.
document.addEventListener('turbo:visit', () => { inHistory = null; });

export function openSheet(d, { history: withHistory = true } = {}) {
    if (d.open) d.close();
    d.classList.remove('is-closing');
    d.style.translate = '';
    d.showModal();
    attachDrag(d);
    if (!d.dataset.cancel) {
        d.dataset.cancel = '1';
        // Esc и CloseWatcher Android — тем же уходом и со снятием записи в истории.
        d.addEventListener('cancel', (e) => { e.preventDefault(); closeSheet(d); });
    }
    if (withHistory && d.id && inHistory !== d.id) {
        const { turbo, ...rest } = history.state || {};
        history.replaceState(rest, '');
        history.pushState({ ...rest, sheet: d.id }, '');
        inHistory = d.id;
    }
}

// Возвращает обещание: история приведена в порядок (запись шторки снята).
export function closeSheet(d, from = 0, viaHistory = false) {
    if (!d.open) return Promise.resolve();
    if (reduce.matches || !d.matches(':modal') || d.classList.contains('is-closing')) { d.close(); return unwind(d, viaHistory); }
    d.style.setProperty('--from', `${from}px`);
    d.style.translate = '';
    d.style.transition = '';
    d.classList.add('is-closing');
    let done = false;
    const finish = () => {
        if (done) return;
        done = true;
        d.classList.remove('is-closing');
        d.style.removeProperty('--from');
        d.close();
    };
    d.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, 350);
    return unwind(d, viaHistory);
}

function unwind(d, viaHistory) {
    if (viaHistory || inHistory !== d.id || history.state?.sheet !== d.id) return Promise.resolve();
    return new Promise((resolve) => { settle = resolve; history.back(); });
}

function attachDrag(d) {
    if (d.dataset.drag) return;
    d.dataset.drag = '1';
    let startY = 0, startAt = 0, dy = 0, active = false;

    d.addEventListener('touchstart', (e) => {
        if (!d.matches(':modal') || reduce.matches || e.touches.length !== 1) return;
        startY = e.touches[0].clientY; startAt = e.timeStamp; dy = 0; active = true;
        d.style.transition = 'none';
    }, { passive: true });

    d.addEventListener('touchmove', (e) => {
        if (!active) return;
        const y = e.touches[0].clientY - startY;
        // Вниз — только когда содержимое у верха; вверх — с сопротивлением.
        if (y > 0 && d.scrollTop <= 0) {
            dy = y;
            d.style.translate = `0 ${y}px`;
            e.preventDefault();
        } else if (y < 0 && dy === 0 && d.scrollTop <= 0 && d.scrollHeight <= d.clientHeight) {
            d.style.translate = `0 ${-Math.pow(-y, .6)}px`;
            e.preventDefault();
        } else if (dy > 0) {
            dy = 0;
            d.style.translate = '';
        }
    }, { passive: false });

    const end = (e) => {
        if (!active) return;
        active = false;
        const speed = dy / Math.max(1, e.timeStamp - startAt);
        if (dy > Math.min(120, d.clientHeight / 3) || speed > .6) { closeSheet(d, dy); return; }
        d.style.transition = 'translate var(--dur-slow) var(--ease-spring)';
        d.style.translate = '';
        d.addEventListener('transitionend', () => { d.style.transition = ''; }, { once: true });
    };
    d.addEventListener('touchend', end);
    d.addEventListener('touchcancel', end);
}
