// Потянуть вниз — обновить (16.10.2026): своё — системного обновления жестом у приложения нет (документ не листается,
// листается #scroller, lib/scroller.js). Резинку #scroller жест не трогает (слушатели пассивные): шапка стоит над
// прокруткой, список отскакивает под ней, кружок выезжает из-под шапки в этот зазор.
// Тянут от самого верха страницы, вниз, пальцем; под шапкой выезжает кружок со стрелкой, дотянули (64 px) — стрелка
// разворачивается, отпустили — refresh() (морф Turbo: прокрутка и открытое остаются), кружок крутится до turbo:load.
// Не тянется: в шторке и карточке строки, во внутренней прокрутке не у её верха, по ленте вбок, с клавиатурой — и
// когда allowed() говорит нельзя (набранное в форме).
import { scrollTop } from './scroller';

const PULL = 64;
const MAX = 96;

export function pullToRefresh(refresh, allowed) {
    if (!matchMedia('(pointer: coarse)').matches) return;
    let start = null;
    let dist = 0;
    let busy = false;
    let el = null;

    const spinner = () => {
        if (el?.isConnected) return el;
        el = document.createElement('div');
        el.className = 'pull';
        el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';
        document.body.append(el);
        return el;
    };
    const paint = (d, ready) => {
        const node = spinner();
        node.style.setProperty('--pull', `${d}px`);
        node.style.setProperty('--pull-p', Math.min(1, d / PULL));
        node.classList.toggle('is-ready', ready);
    };
    const hide = () => {
        if (!el) return;
        el.classList.remove('is-loading', 'is-ready');
        el.classList.add('is-leaving');
        const node = el;
        el = null;
        setTimeout(() => node.remove(), 250);
        busy = false;
    };

    const scrolledInside = (target) => {
        for (let n = target; n && n !== document.body; n = n.parentElement) {
            if (n.scrollTop > 0) return true;
        }
        return false;
    };

    document.addEventListener('touchstart', (e) => {
        start = null;
        if (busy || e.touches.length !== 1 || scrollTop() > 0) return;
        const t = e.target;
        // Тянут список: шапка и таб-бар — вне прокрутки, жест по ним не обновляет (как навбар iOS).
        if (!t.closest?.('#scroller') && document.getElementById('scroller')) return;
        if (t.closest?.('dialog, .detail, [data-no-pull], .chat-page') || scrolledInside(t)) return;
        if (document.documentElement.classList.contains('kb-open') || !allowed()) return;
        start = { x: e.touches[0].clientX, y: e.touches[0].clientY, decided: false };
        dist = 0;
    }, { passive: true });

    document.addEventListener('touchmove', (e) => {
        if (!start) return;
        const dx = e.touches[0].clientX - start.x;
        const dy = e.touches[0].clientY - start.y;
        if (!start.decided) {
            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            // Вбок (лента плиток) или вверх (обычная прокрутка) — не наш жест.
            if (dy <= 0 || Math.abs(dx) > dy) { start = null; return; }
            start.decided = true;
        }
        dist = Math.min(MAX, Math.max(0, dy) * 0.5);
        paint(dist, dist >= PULL);
    }, { passive: true });

    const end = () => {
        if (!start?.decided) { start = null; return; }
        start = null;
        if (dist < PULL || !allowed()) { hide(); return; }
        busy = true;
        paint(PULL, false);
        el.classList.add('is-loading');
        const done = () => { clearTimeout(guard); document.removeEventListener('turbo:load', done); hide(); };
        const guard = setTimeout(done, 15_000);
        document.addEventListener('turbo:load', done);
        refresh();
    };
    document.addEventListener('touchend', end, { passive: true });
    document.addEventListener('touchcancel', () => { start = null; hide(); }, { passive: true });
}
