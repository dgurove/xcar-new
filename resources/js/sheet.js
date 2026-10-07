// Шторка на <dialog>, общее для sheet_controller, share_controller и confirm:
// открытие — выезд снизу (CSS sheet-up), уход — класс is-closing с анимацией
// вниз и close() по её концу; свайп вниз — только намеренный (attachDrag), набранное
// без вопроса не теряется (dismiss), фон — по затемнению, а не по отступам листа (backdrop).
// 07.10.2026 владелец: окна «постоянно норовят закрыться», сумму в «Ссылке на оплату» не ввести.
// Открытая шторка — запись в истории: свайп от края и «Назад» закрывают её, а
// не страницу. Чтобы Turbo не делал restore-визит на этот popstate, у записи
// страницы на время шторки снимается ключ turbo (пустое состояние Turbo лишь
// переписывает, не перерисовывая); закрытие крестиком само снимает запись
// через history.back().
import { confirmSheet } from './confirm';

export const reduce = matchMedia('(prefers-reduced-motion: reduce)');
let inHistory = null; // id шторки, чья запись сверху истории
let settle = null;    // ждёт popstate после history.back() из closeSheet
let pageTurbo;        // снятый у записи страницы ключ turbo — вернуть после шторки

window.addEventListener('popstate', (event) => {
    const under = event.state?.sheet;
    // Шторка поверх шторки («Распознать» над окном писем): запись нижней снова сверху — это возврат к ней, не «вперёд».
    const lower = under && under !== inHistory && document.getElementById(under)?.open;
    const forward = under && under !== inHistory && !lower;
    if (!inHistory && !forward) return;
    if (forward) { history.back(); return; }
    const d = document.getElementById(inHistory);
    // «Назад» (свайп от края, кнопка Android) при набранном в листе: запись вернуть, лист оставить и спросить.
    if (!settle && !lower && d?.open && dirty(d)) {
        history.pushState({ ...(history.state || {}), sheet: d.id }, '');
        dismiss(d);
        return;
    }
    inHistory = lower ? under : null;
    if (d?.open) closeSheet(d, 0, true);
    // Под шторкой — запись другой шторки: она остаётся своей (Turbo на пустой записи ставит свой
    // ключ — тогда её не снять «Назад»). Страница снова сверху — ключ turbo на месте, «‹ Раздел» пойдёт шагом по истории.
    if (lower) history.replaceState(event.state, '');
    else if (pageTurbo !== undefined) history.replaceState({ ...(history.state || {}), turbo: pageTurbo }, '');
    settle?.();
    settle = null;
});
// Чем открыли последнее: пальцем или мышью — кольцо фокуса на «×» после showModal снимаем, клавиатуре оставляем.
let byPointer = false;
addEventListener('pointerdown', () => { byPointer = true; }, { capture: true, passive: true });
addEventListener('keydown', () => { byPointer = false; }, { capture: true, passive: true });

// Визит уводит со страницы (или заменяет запись) — верхняя запись больше не шторка.
document.addEventListener('turbo:visit', () => { inHistory = null; });

// Запись шторки ещё в истории. Снимок страницы (turbo:before-cache) в этот момент — не уход со страницы: шторку сняли
// «Назад» или крестиком, запись под ней без ключа Turbo, и Turbo на этом popstate снимает снимок раньше нас. Шторка
// документов под ней не закрывается (docs_controller); у визита запись уже снята.
export const sheetInHistory = () => inHistory !== null;

export function openSheet(d, { history: withHistory = true } = {}) {
    if (d.open) d.close();
    d.classList.remove('is-closing');
    d.style.translate = '';
    d.showModal();
    d.openedAt = performance.now();
    // showModal ставит фокус на первую кнопку («×»), и та рисует кольцо — у открытых пальцем или мышью снимаем.
    if ((byPointer || matchMedia('(hover: none)').matches) && document.activeElement?.matches('button, a')) document.activeElement.blur();
    attachDrag(d);
    if (!d.dataset.cancel) {
        d.dataset.cancel = '1';
        // Esc и CloseWatcher Android — тем же уходом и со снятием записи в истории; набранное — с вопросом.
        d.addEventListener('cancel', (e) => { e.preventDefault(); dismiss(d); });
    }
    if (withHistory && d.id && inHistory !== d.id) {
        const { turbo, ...rest } = history.state || {};
        // Ключ страницы снимает только первая шторка: у второй под ней запись шторки, ключа там нет.
        if (!inHistory) pageTurbo = turbo;
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

// Поле, которое держит клавиатуру: на iOS её видно по html.kb-open (app.js), на Android viewport ужимается сам — там по фокусу.
const TYPING = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=button]):not([type=submit]):not([type=file]), textarea, [contenteditable="true"], trix-editor';
const typing = (d) => document.documentElement.classList.contains('kb-open') || (d.contains(document.activeElement) && document.activeElement.matches(TYPING));

// Нажатие мимо листа — по затемнению, а не по его отступам: target === dialog бывает и у паддинга, ручки и щелей
// между блоками.
export function outside(d, e) {
    if (e.target !== d) return false;
    const r = d.getBoundingClientRect();
    return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
}

// Несохранённое в формах листа (не поиск и не фильтр — у них GET): поле, галка или выбор не такие, как пришли с сервера.
export function dirty(d) {
    for (const form of d.querySelectorAll('form')) {
        if (form.method.toLowerCase() === 'get' || form.hasAttribute('data-sheet-clean')) continue;
        for (const el of form.elements) {
            if (el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') continue;
            if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked !== el.defaultChecked) return true; continue; }
            if (el.tagName === 'SELECT') {
                const opts = [...el.options];
                // Без selected в разметке по умолчанию выбран первый (у multiple — ни одного).
                const preset = opts.some((o) => o.defaultSelected);
                if (opts.some((o, i) => o.selected !== (preset ? o.defaultSelected : (!el.multiple && i === 0)))) return true;
                continue;
            }
            if ('defaultValue' in el && el.value !== el.defaultValue) return true;
        }
    }
    return false;
}

// Закрыть по просьбе человека («×», Esc, «Назад», свайп): набранное в листе не теряется молча — спросить.
export async function dismiss(d, from = 0) {
    if (!d.open) return;
    if (dirty(d) && !(await confirmSheet('Не сохранять?', { label: 'Закрыть', cancel: 'Остаться', danger: true }))) return;
    closeSheet(d, from);
}

// Лист «живой», но не уходит: несохранённое или фон при клавиатуре — короткий толчок вниз и обратно.
function nudge(d) {
    if (!reduce.matches) d.animate([{ translate: '0 0' }, { translate: '0 8px' }, { translate: '0 0' }], { duration: 260, easing: 'ease-out' });
}

// Нажатие по затемнению: с клавиатурой — только убрать её (так привыкли закрывать клавиатуру), с несохранённым —
// толчок, без вопроса (промах пальцем не должен ничего спрашивать), иначе — закрыть.
export function backdrop(d, e, close = () => closeSheet(d)) {
    if (!d.matches(':modal') || !outside(d, e)) return;
    if (typing(d)) { document.activeElement?.blur(); return; }
    if (dirty(d)) { nudge(d); return; }
    close();
}

// Свайп вниз — как у шторок iOS, и только намеренный:
// - тянут за верх — ручку и строку заголовка (`.sheet-bar`) или верхние 56 px; тело листа просто листается. Где в
//   листе нет полей и прокрутки (меню, подтверждение), тянется и тело;
// - лист трогается после 10 px вертикального жеста и едет от этой точки, а не от начала касания;
// - закрывает, если отпустили ниже 40 % высоты (не меньше 140 px) или взмахнули быстрее 1,1 px/мс, пройдя от 60 px;
//   скорость — по последним 80 мс; иначе пружиной обратно;
// - открыта клавиатура — жест её убирает, лист остаётся; первые 300 мс после открытия лист не тянется;
// - несохранённое — после жеста спросить «Не сохранять?» (dismiss).
const GRIP = 56, SLOP = 10, OPEN_GRACE = 300;
const FIELDS = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select, [contenteditable="true"], trix-editor';

function grip(d, e) {
    const t = e.target instanceof Element ? e.target : null;
    if (t?.closest('.sheet-bar')) return true;
    if (t?.closest(FIELDS)) return false;
    const y = e.touches[0].clientY - d.getBoundingClientRect().top;
    if (y <= GRIP) return true;
    return !d.querySelector(FIELDS) && d.scrollHeight <= d.clientHeight + 1 && !scrolls(t, d);
}

// Под пальцем вложенный блок со своей прокруткой (список, оценка) — палец листает его.
function scrolls(t, d) {
    for (let el = t; el && el !== d; el = el.parentElement) {
        if (el.scrollHeight > el.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(el).overflowY)) return true;
    }
    return false;
}

function attachDrag(d) {
    if (d.dataset.drag) return;
    d.dataset.drag = '1';
    let x0 = 0, y0 = 0, base = 0, dy = 0, state = 'idle', pts = [];

    d.addEventListener('touchstart', (e) => {
        state = 'idle';
        if (!d.matches(':modal') || reduce.matches || e.touches.length !== 1) return;
        if (performance.now() - (d.openedAt ?? 0) < OPEN_GRACE || !grip(d, e)) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dy = 0; pts = []; state = 'armed';
    }, { passive: true });

    d.addEventListener('touchmove', (e) => {
        if (state === 'idle') return;
        const t = e.touches[0], mx = t.clientX - x0, my = t.clientY - y0;
        if (state === 'armed') {
            if (Math.hypot(mx, my) < SLOP) return;
            // Вбок (чипы, полоса фото) или вверх — не наш жест.
            if (my <= 0 || Math.abs(my) < 1.5 * Math.abs(mx)) { state = 'idle'; return; }
            if (typing(d)) { document.activeElement?.blur(); state = 'idle'; return; }
            state = 'drag'; base = t.clientY;
            d.style.transition = 'none';
        }
        const y = t.clientY - base;
        // Вниз — за пальцем, вверх — с сопротивлением.
        dy = Math.max(0, y);
        d.style.translate = `0 ${y >= 0 ? y : -Math.pow(-y, .6)}px`;
        pts.push([e.timeStamp, t.clientY]);
        while (pts.length > 2 && e.timeStamp - pts[0][0] > 80) pts.shift();
        e.preventDefault();
    }, { passive: false });

    const end = (e) => {
        if (state !== 'drag') { state = 'idle'; return; }
        state = 'idle';
        const [t0, p0] = pts[0] ?? [e.timeStamp, 0], [t1, p1] = pts[pts.length - 1] ?? [e.timeStamp, 0];
        const speed = (p1 - p0) / Math.max(16, t1 - t0);
        const far = dy > Math.max(140, d.clientHeight * .4), flung = speed > 1.1 && dy >= 60;
        if ((far || flung) && !dirty(d)) { closeSheet(d, dy); return; }
        d.style.transition = 'translate var(--dur-slow) var(--ease-spring)';
        d.style.translate = '';
        d.addEventListener('transitionend', () => { d.style.transition = ''; }, { once: true });
        if (far || flung) dismiss(d);
    };
    d.addEventListener('touchend', end);
    d.addEventListener('touchcancel', end);
}
