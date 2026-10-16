// Шторка на <dialog>, общее для sheet_controller, share_controller и confirm:
// открытие — выезд снизу (CSS sheet-up), уход — класс is-closing с анимацией
// вниз и close() по её концу; свайп вниз — только намеренный (attachDrag), набранное
// без вопроса не теряется (dismiss), фон — по затемнению, а не по отступам листа (backdrop).
// 07.10.2026 владелец: окна «постоянно норовят закрыться», сумму в «Ссылке на оплату» не ввести.
// Открытая шторка — запись в истории (overlays.js): свайп от края и «Назад» закрывают её, а не страницу; закрытие
// крестиком снимает запись само.
import { confirmSheet } from './confirm';
import { focusField } from './keyboard';
import * as overlays from './overlays';
import { fling } from './lib/fling';

export const reduce = matchMedia('(prefers-reduced-motion: reduce)');
// Чем открыли последнее: пальцем или мышью — кольцо фокуса на «×» после showModal снимаем, клавиатуре оставляем.
let byPointer = false;
addEventListener('pointerdown', () => { byPointer = true; }, { capture: true, passive: true });
addEventListener('keydown', () => { byPointer = false; }, { capture: true, passive: true });

// Открыта модальная шторка — класс sheet-open на <html> (страница под ней не листается, app.css; фон держит
// holdBackground). Класс, а не html:has(dialog.sheet:modal): тот WebKit пересчитывал на каждую правку DOM.
const syncOpen = () => document.documentElement.classList.toggle('sheet-open', !!document.querySelector('dialog.sheet:modal'));
document.addEventListener('close', syncOpen, true);
// Открытая шторка ушла из DOM без close (подмена фрейма, поток replace) — класс иначе остался бы, и страница на телефоне
// не листалась бы до следующего визита.
for (const name of ['turbo:render', 'turbo:frame-render', 'turbo:load']) document.addEventListener(name, syncOpen);
document.addEventListener('turbo:before-stream-render', () => requestAnimationFrame(syncOpen));

// Фон под шторкой не тянется (15.10.2026, владелец: «пытаешься листать — сдвигается всё окно»). Пока стоит клавиатура,
// iOS отдаёт жест по тому, что не листается (короткий список, лист без прокрутки), всему экрану — едет видимая часть,
// с ней --kb и лист. Ведёт палец то, что под ним может прокрутиться в эту сторону; нечему — жест отменяется. Поле ввода
// (каретка, выделение) не трогаем. Лист карточки строки с клавиатурой — так же. Слои поверх шторки (документ, просмотр
// фото, подтверждение не-шторкой) — свои: их касания не трогаем.
// Что под пальцем может листаться, ищется раз на касание (стили предков), на каждом шаге жеста — только положение прокрутки.
function scrollers(el, box) {
    const found = [];
    for (let n = el; n; n = n.parentElement) {
        const cs = getComputedStyle(n);
        const y = n.scrollHeight > n.clientHeight + 1 && /(auto|scroll)/.test(cs.overflowY);
        const x = n.scrollWidth > n.clientWidth + 1 && /(auto|scroll)/.test(cs.overflowX);
        if (x || y) found.push({ n, x, y });
        if (n === box) break;
    }
    return found;
}
function scrollsToward(found, dx, dy) {
    const vertical = Math.abs(dy) >= Math.abs(dx);
    return found.some(({ n, x, y }) => {
        if (vertical) return y && (dy > 0 ? n.scrollTop > 0 : n.scrollTop + n.clientHeight < n.scrollHeight - 1);
        const left = Math.abs(n.scrollLeft);
        return x && (dx > 0 ? left > 0 : left + n.clientWidth < n.scrollWidth - 1);
    });
}
(function holdBackground() {
    let x0 = 0, y0 = 0, box = null, found = [];
    addEventListener('touchstart', (e) => {
        const root = document.documentElement;
        box = null;
        if (root.classList.contains('sheet-open') && !document.querySelector('dialog.sheet:modal')) syncOpen();
        const target = e.target instanceof Element ? e.target : null;
        if (!target || e.touches.length !== 1) return;
        // Поле в фокусе — его касание ставит каретку и тянет выделение, многострочное листается само; остальное поле —
        // такой же лист: в форме поля почти везде, и палец с поля раньше тащил всё окно.
        const field = target.closest('input, textarea, [contenteditable="true"]');
        if (field && (field === document.activeElement || field.scrollHeight > field.clientHeight + 1)) return;
        // Касание в своём слое поверх шторки (dialog.docs, dialog.lightbox) — не наше.
        const layer = target.closest('dialog');
        if (layer && !layer.matches('.sheet')) return;
        box = root.classList.contains('sheet-open') ? target.closest('dialog.sheet:modal')
            : root.classList.contains('kb-open') ? target.closest('.split-detail') : null;
        if (!box) return;
        const t = e.touches[0];
        x0 = t.clientX; y0 = t.clientY;
        found = scrollers(target, box);
    }, { capture: true, passive: true });
    addEventListener('touchmove', (e) => {
        if (!box || e.defaultPrevented || e.touches.length !== 1) return;
        const t = e.touches[0], dx = t.clientX - x0, dy = t.clientY - y0;
        x0 = t.clientX; y0 = t.clientY;
        if (scrollsToward(found, dx, dy)) return;
        e.preventDefault();
    }, { passive: false });
})();

// Отправка из шторки несёт _sheet — id шторки: вернулись ошибки проверки — x-ui.sheet открывается сам по old('_sheet'),
// какое бы поле ни было неверным (раньше шторки перечисляли «свои» поля руками: ошибка в забытом — шторка закрыта,
// сохранение молча не прошло). Через событие formdata (его шлёт и Turbo, и обычная отправка), а не скрытым полем: морф
// страницы снял бы поле, которого нет в разметке.
document.addEventListener('formdata', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get') return;
    // Форма бывает вне шторки, а поля и кнопка — в ней (form=): тогда шторка — та, где её поля.
    const d = form.closest('dialog.sheet[id]') ?? [...form.elements].find((el) => el.closest('dialog.sheet[id][open]'))?.closest('dialog.sheet[id]');
    if (d && !e.formData.has('_sheet')) e.formData.set('_sheet', d.id);
});

// Набранное в форме шторки переживает выгрузку приложения из фона и выкладку (16.10.2026): каждая форма шторки с
// записью получает черновик (draft_controller) — ключом адрес страницы, шторка и адрес формы. «Не сохранять» и отправка
// его стирают: ошибки проверки сервер возвращает в поля сам (old()).
function prepareForms(d) {
    for (const form of d.querySelectorAll('form')) {
        if (form.method.toLowerCase() === 'get' || form.hasAttribute('data-sheet-clean')) continue;
        for (const el of form.querySelectorAll('input[type=hidden]:not([name="_token"]):not([name="_method"])')) el.dataset.sheetWas = el.value;
        if (form.matches('[data-controller~="draft"]')) continue;
        form.dataset.draftKeyValue ??= `sheet:${location.pathname}:${d.id}:${new URL(form.action || location.href, location.href).pathname}`;
        form.dataset.draftSentValue = 'clear';
        form.dataset.controller = `${form.dataset.controller ?? ''} draft`.trim();
    }
}

export function openSheet(d, { history: withHistory = true } = {}) {
    if (d.open) d.close();
    d.classList.remove('is-closing');
    d.style.translate = '';
    // Своё поле с autofocus фокусирует keyboard.js с ожиданием клавиатуры: showModal сфокусировал бы его сам, без неё,
    // и лист сперва встал бы без клавиатуры, а потом прыгнул.
    const auto = d.querySelector('[autofocus]');
    auto?.removeAttribute('autofocus');
    d.showModal();
    prepareForms(d);
    if (auto) { auto.setAttribute('autofocus', ''); focusField(auto); }
    syncOpen();
    d.openedAt = performance.now();
    // showModal ставит фокус на первую кнопку («×»), и та рисует кольцо — у открытых пальцем или мышью снимаем.
    if ((byPointer || matchMedia('(hover: none)').matches) && document.activeElement?.matches('button, a')) document.activeElement.blur();
    attachDrag(d);
    if (!d.dataset.cancel) {
        d.dataset.cancel = '1';
        // Esc и CloseWatcher Android — тем же уходом и со снятием записи в истории; набранное — с вопросом.
        d.addEventListener('cancel', (e) => { e.preventDefault(); dismiss(d); });
    }
    // «Назад» закрывает шторку, а не страницу (overlays.js); набранное — с вопросом.
    if (withHistory && d.id) overlays.open(`sheet:${d.id}`, { close: () => closeSheet(d, 0, true), guard: () => dirty(d) && leave(d) });
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
    return viaHistory ? Promise.resolve() : overlays.shut(`sheet:${d.id}`);
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

// «Не сохранять?» — да: формы листа возвращаются к тому, что пришло с сервера (иначе открытый снова лист спрашивал бы
// опять), черновики стираются.
async function leave(d) {
    if (!(await confirmSheet('Не сохранять?', { label: 'Закрыть', cancel: 'Остаться', danger: true }))) return false;
    d.querySelectorAll('form').forEach((form) => {
        if (form.method.toLowerCase() === 'get' || form.hasAttribute('data-sheet-clean')) return;
        const was = new Map([...form.elements].map((el) => [el, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value]));
        form.reset();
        // Скрытое поле (id из подсказки комбобокса) сброс не возвращает — значение с открытия листа.
        for (const el of form.querySelectorAll('input[type=hidden][data-sheet-was]')) el.value = el.dataset.sheetWas;
        // Что сброс вернул — тем, кто рисует по полю (чипы меток, раскрытые панели, итоги): иначе они остались бы на
        // брошенном.
        for (const [el, value] of was) {
            if ((el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value) === value) continue;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        form.dispatchEvent(new CustomEvent('draft:clear'));
    });
    return true;
}

// Закрыть по просьбе человека («×», Esc, свайп): набранное в листе не теряется молча — спросить.
export async function dismiss(d, from = 0) {
    if (!d.open) return;
    if (dirty(d) && !(await leave(d))) return;
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
    let x0 = 0, y0 = 0, base = 0, dy = 0, state = 'idle', track = fling();

    d.addEventListener('touchstart', (e) => {
        state = 'idle';
        if (!d.matches(':modal') || reduce.matches || e.touches.length !== 1) return;
        if (performance.now() - (d.openedAt ?? 0) < OPEN_GRACE || !grip(d, e)) return;
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dy = 0; track = fling(); state = 'armed';
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
        track.add(e.timeStamp, t.clientY);
        e.preventDefault();
    }, { passive: false });

    const end = (e) => {
        if (state !== 'drag') { state = 'idle'; return; }
        state = 'idle';
        const speed = track.speed();
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
