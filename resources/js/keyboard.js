// Клавиатура — одно место на приложение (16.10.2026, владелец: «всё съезжает и ломается при начале ввода текста»; до
// того клавиатуру по-своему слушали app.js, календарь, комбобокс и шторка документов).
//
// iOS клавиатурой не ужимает layout viewport: 100dvh и fixed-низ остаются под ней. Отсюда на <html>:
// - класс kb-open — клавиатура есть (поле в фокусе и видимая часть ниже окна); от прокрутки не зависит, иначе таб-бар
//   мигал на каждый сдвиг экрана к полю;
// - --kb — сколько низа окна закрыто (окно минус видимая часть с её сдвигом): на неё садятся лист, карточка строки,
//   поле чата;
// - --vv-h — высота видимой части (без клавиатуры — всё окно): по ней шторка влезает целиком.
// Остальным — событие `keyboard:change` на document, detail { open, kb, height, bottom } (bottom — низ видимой части в
// координатах окна). Своих слушателей visualViewport ни у кого больше нет.
// Android с interactive-widget=resizes-content ужимает окно сам: --kb там 0, а клавиатуру видно по тому, что окно стало
// ниже обычного для ориентации, пока в поле фокус.
//
// Поле в листе (карточка строки на телефоне, шторка) iOS не должен сдвигать к нему экран (07.10.2026, владелец: лист
// уезжает, каретка «на строку ниже»): при сдвиге WebKit переставляет fixed-слои уже после того, как посчитал каретку.
// Поэтому нажатие по полю в листе фокусирует его само: лист заранее встаёт на клавиатуру (её высота — прошлый замер для
// этого вида клавиатуры, впервые 45 % экрана), поле подтягивается своей прокруткой в видимую часть, каретка встаёт туда,
// куда нажали, и iOS двигать уже нечего. Пришла настоящая высота — меняется только низ листа, поле стоит.
// Страница приложения листается так же, своим #scroller (22.10.2026, lib/scroller.js), — и поле страницы на телефоне
// фокусируется тем же путём, как у Ionic: сдвинутый iOS экран после клавиатуры и тащил таб-бар за собой.
import { pageEdges, scroller } from './lib/scroller';

const vv = window.visualViewport;
const root = document.documentElement;
const ios = /iP(hone|ad|od)/.test(navigator.platform) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
// Календарь и список — не клавиатура: их шторка viewport не ужимает, и сдвигать под неё ничего не надо.
export const TYPED = 'input:not([type=date]):not([type=datetime-local]):not([type=time]):not([type=month]):not(.date-input):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=color]), textarea, [contenteditable="true"], trix-editor';
const typing = () => document.activeElement?.matches?.(TYPED) ?? false;

// Ожидаемая высота — по ориентации и виду клавиатуры: у цифровой, почты и текста с подсказками она разная, и
// одна догадка на всех давала двойной сдвиг листа при переходе «Далее» между полями.
const orient = () => (innerWidth > innerHeight ? 'l' : 'p');
const kind = (el) => {
    const mode = el?.inputMode || el?.getAttribute?.('inputmode');
    if (mode === 'decimal' || mode === 'numeric' || el?.type === 'number') return 'num';
    if (el?.type === 'tel' || mode === 'tel') return 'tel';
    if (el?.type === 'email' || mode === 'email') return 'email';
    return 'text';
};
const read = (key) => { try { const v = Number(localStorage.getItem(key)); return v > 100 ? v : 0; } catch { return 0; } };
const guess = (el) => read(`kb:${orient()}:${kind(el)}`) || read(`kb:${orient()}`) || Math.round(innerHeight * .45);
let remembered = '';
const remember = (el, kb) => {
    // Пишем, только когда замер новый: visualViewport шлёт scroll каждый кадр, а запись в хранилище не бесплатна.
    const key = `${orient()}:${kind(el)}:${kb}`;
    if (key === remembered) return;
    remembered = key;
    try {
        localStorage.setItem(`kb:${orient()}:${kind(el)}`, String(kb));
        localStorage.setItem(`kb:${orient()}`, String(kb));
    } catch {}
};

let state = { open: false, kb: 0, height: vv?.height ?? innerHeight, bottom: innerHeight };
export const keyboard = () => state;

let last = '';
const apply = (kb, height, top, open) => {
    // Переменные корня наследует весь документ — пишем, только когда что-то поменялось: scroll visualViewport идёт
    // каждый кадр, и каждая запись пересчитывала стили всей страницы.
    const key = `${kb}|${height}|${open}`;
    if (key === last) return;
    last = key;
    root.style.setProperty('--kb', `${kb}px`);
    root.style.setProperty('--vv-h', `${height}px`);
    root.classList.toggle('kb-open', open);
    state = { open, kb, height, bottom: top + height };
    document.dispatchEvent(new CustomEvent('keyboard:change', { detail: state }));
};

// Android: окно ниже обычного для ориентации, пока в поле фокус.
const tallest = {};
const shrunk = () => {
    if (!typing()) { tallest[orient()] = Math.max(tallest[orient()] ?? 0, innerHeight); return false; }
    return !ios && (tallest[orient()] ?? innerHeight) - innerHeight > 150;
};

let predicted = 0, until = 0, guessed = null;
// Внешняя клавиатура (iPad с клавиатурой): ожидание вышло, а экранной так и нет — дальше нажатие по полю в фокусе
// отдаём браузеру, как с открытой.
let hardware = false;
const measure = () => {
    if (!vv) return;
    const field = typing();
    // Без поля в фокусе клавиатуры нет — разница высот от резины или зума не считается за неё.
    const hidden = vv.scale > 1.01 || !field ? 0 : Math.max(0, Math.round(innerHeight - vv.height));
    const kb = hidden ? Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop)) : 0;
    if (hidden > 100) {
        predicted = 0;
        hardware = false;
        remember(document.activeElement, hidden);
    }
    // Клавиатура ещё выезжает — держим ожидаемую высоту, иначе лист съездил бы вниз и обратно.
    if (hidden <= 100 && predicted && field && performance.now() < until) { apply(predicted, innerHeight - predicted, 0, true); return; }
    // Увеличили щипком (на ПК) — видимая часть меньше окна, но это не клавиатура: высота листа — от окна.
    apply(kb, Math.round(vv.scale > 1.01 ? innerHeight : vv.height), Math.round(vv.scale > 1.01 ? 0 : vv.offsetTop), hidden > 100 || shrunk());
};
// Из событий visualViewport — раз за кадр.
let queued = 0;
const update = () => { if (!queued) queued = requestAnimationFrame(() => { queued = 0; measure(); }); };

// Лист, где поле держит клавиатуру: карточка строки на телефоне, шторка, на сенсорном экране — сама страница
// (#scroller, поле содержимого; поиск в шапке — браузеру). Всплывашка календаря — свой лист со своим местом
// (date_controller): шторку под ней не двигаем.
const host = (el) => {
    if (el?.closest?.('.date-pop')) return null;
    return el?.closest?.('dialog.sheet:modal')
        ?? (matchMedia('(max-width: 1023.98px)').matches ? el?.closest?.('.split-detail') : null)
        ?? (matchMedia('(hover: none)').matches && el?.closest?.('#main') ? el.closest('#scroller') : null);
};
// Поле — в видимой части над клавиатурой (низ — bottom), в верхней трети: прокруткой своего листа, не страницы.
const reveal = (el, bottom) => {
    const sheet = host(el);
    if (!sheet) return;
    let box = el.parentElement;
    for (; box && box !== sheet.parentElement; box = box.parentElement) {
        if (box.scrollHeight > box.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(box).overflowY)) break;
    }
    if (!box || box === sheet.parentElement) return;
    const r = el.getBoundingClientRect(), b = box.getBoundingClientRect();
    // Страница — под липкой шапкой и над плашкой действий, лист — во всю свою высоту.
    const page = box === scroller() ? pageEdges(bottom) : null;
    const top = page?.top ?? Math.max(b.top, 0), end = page?.bottom ?? Math.min(b.bottom, bottom);
    if (r.top >= top + 8 && r.bottom <= end - 16) return;
    box.scrollTop += r.top - (top + (end - top) / 3);
};

// Лист заранее на ожидаемой клавиатуре, поле — в его видимой части, фокус без сдвига экрана.
const expect = (el, { touch = false } = {}) => {
    predicted = guess(el);
    guessed = el;
    until = performance.now() + 900;
    apply(predicted, innerHeight - predicted, 0, true);
    reveal(el, innerHeight - predicted);
    el.focus({ preventScroll: true });
    // Клавиатура так и не вышла — ожидание снимается.
    setTimeout(() => {
        if (!predicted || guessed !== el) return;
        predicted = 0;
        if (touch && document.activeElement === el) hardware = true;
        measure();
    }, 900);
};

// Фокус в поле программно (кнопка «+ метка», «Ещё ступень», ошибка проверки формы, autofocus шторки): тем же путём, что
// нажатие по полю, — лист заранее на ожидаемой клавиатуре, поле — прокруткой своего листа, iOS экран не двигает. Без
// клавиатуры (страница только что пришла с ошибкой) — только прокрутка листа к полю, без фокуса.
export const focusField = (el, { keyboard = true } = {}) => {
    if (!el) return;
    const sheet = host(el);
    if (!sheet || !matchMedia('(hover: none)').matches) {
        el.focus({ preventScroll: true });
        // К полю — только если его не видно: поле в шапке (поиск) и так на экране, и прокрутка увела бы страницу.
        const r = el.getBoundingClientRect();
        const top = Math.max(document.querySelector('.header')?.getBoundingClientRect().bottom ?? 0, 0);
        if (r.top < top || r.bottom > (state.open ? state.bottom : innerHeight)) el.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' });
        return;
    }
    // Не из нажатия (карточка пришла, лист открыл сервер) клавиатура iOS не выйдет — без фокуса, иначе поле в фокусе
    // без клавиатуры не ловило своё нажатие.
    if (navigator.userActivation && !navigator.userActivation.isActive) keyboard = false;
    if (!keyboard || !ios || !el.matches(TYPED)) { reveal(el, innerHeight - (ios && keyboard && el.matches(TYPED) ? guess(el) : 0)); if (keyboard) el.focus({ preventScroll: true }); return; }
    expect(el);
};
// Прокрутка к узлу внутри листа — прокруткой самого листа: scrollIntoView листал и страницу под шторкой.
export const revealInSheet = (el) => reveal(el, innerHeight);

// Куда в тексте поля попал палец: нажатие перехвачено, и браузер каретку сам не поставит (раньше она всегда уезжала в
// конец — поправить букву в середине было нельзя). Однострочное поле — замером текста его шрифтом, многострочное —
// невидимой копией текста поверх поля. Не вышло — в конец.
let pen = null;
const caretAt = (el, x, y) => {
    const value = el.value ?? '';
    if (!value) return 0;
    const s = getComputedStyle(el), r = el.getBoundingClientRect();
    if (el.tagName === 'INPUT') {
        pen ??= document.createElement('canvas').getContext('2d');
        pen.font = `${s.fontStyle} ${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
        const spacing = parseFloat(s.letterSpacing) || 0;
        const text = el.type === 'password' ? '•'.repeat(value.length) : value;
        const width = (n) => pen.measureText(text.slice(0, n)).width + spacing * n;
        const inner = r.width - parseFloat(s.borderLeftWidth) - parseFloat(s.borderRightWidth) - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
        const full = width(text.length);
        const shift = s.textAlign === 'right' || s.textAlign === 'end' ? Math.max(0, inner - full) : s.textAlign === 'center' ? Math.max(0, (inner - full) / 2) : 0;
        const at = x - (r.left + parseFloat(s.borderLeftWidth) + parseFloat(s.paddingLeft) + shift) + el.scrollLeft;
        if (at <= 0) return 0;
        if (at >= full) return text.length;
        let best = 0, gap = Infinity;
        for (let i = 0; i <= text.length; i++) {
            const d = Math.abs(width(i) - at);
            if (d < gap) { gap = d; best = i; } else break;
        }
        return best;
    }
    if (el.tagName === 'TEXTAREA' && document.caretRangeFromPoint) {
        const copy = document.createElement('div');
        for (const p of ['font', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'lineHeight', 'textAlign', 'textIndent', 'wordSpacing', 'tabSize', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'boxSizing']) copy.style[p] = s[p];
        Object.assign(copy.style, {
            position: 'fixed', left: `${r.left}px`, top: `${r.top}px`, width: `${r.width}px`, height: `${r.height}px`,
            overflow: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', borderStyle: 'solid', borderColor: 'transparent',
            opacity: '0', zIndex: '2147483647', margin: '0', pointerEvents: 'auto',
        });
        copy.textContent = value;
        document.body.append(copy);
        copy.scrollTop = el.scrollTop;
        const range = document.caretRangeFromPoint(x, y);
        const at = range && copy.contains(range.startContainer) ? range.startOffset : null;
        copy.remove();
        if (at !== null) return Math.min(at, value.length);
    }
    return value.length;
};

export function keyboardInset() {
    window.focusField = focusField;
    // Клавиатура ужимает окно (Android, interactive-widget=resizes-content), а не ложится поверх, как на iOS.
    if (!ios) root.classList.add('kb-resizes');
    window.revealInSheet = revealInSheet;
    if (!vv) return;
    if (ios) {
        // Нажатие по полю листа: палец не ехал (не прокрутка) и не держали (не выделение). Поле в фокусе с открытой
        // клавиатурой — браузеру: там нажатие ставит каретку само. В фокусе без клавиатуры (фокус дали программно,
        // autofocus шторки) — тоже наш путь, иначе iOS сдвигал лист сам.
        let touch = null;
        document.addEventListener('touchstart', (e) => {
            const t = e.touches[0];
            touch = e.touches.length === 1 ? { x: t.clientX, y: t.clientY, at: e.timeStamp } : null;
        }, { capture: true, passive: true });
        document.addEventListener('touchend', (e) => {
            const from = touch;
            touch = null;
            const el = e.target instanceof Element ? e.target.closest('input, textarea') : null;
            if (!from || !el || !el.matches(TYPED) || el.disabled || el.readOnly) return;
            const focused = el === document.activeElement;
            if (focused && (state.open || hardware)) return;
            const sheet = host(el), t = e.changedTouches[0];
            if (!sheet || Math.hypot(t.clientX - from.x, t.clientY - from.y) > 10 || e.timeStamp - from.at > 500) return;
            e.preventDefault();
            // Карточка строки — сразу во весь экран, без анимации: лист, который едет, пока в нём каретка, рисует её мимо.
            if (sheet.matches('.split-detail')) {
                sheet.dispatchEvent(new CustomEvent('detail:hold'));
                sheet.getAnimations().forEach((a) => a.cancel());
                sheet.removeAttribute('data-moving');
                sheet.style.transition = '';
                sheet.style.transform = '';
                sheet.setAttribute('data-full', '');
            }
            const caret = caretAt(el, t.clientX, t.clientY);
            // Повторный фокус в жесте открывает клавиатуру, которую не открыл программный.
            if (focused) el.blur();
            expect(el, { touch: true });
            try { el.setSelectionRange(caret, caret); } catch {}
            // Нажатие отменено — щелчок, которого ждут поля (комбобокс, копирование), отдаём сами.
            el.click();
        }, { capture: true, passive: false });
        // «Далее» на клавиатуре: следующее поле в видимую часть до того, как iOS решит сдвигать экран (focusin идёт
        // раньше, чем WebKit сообщает о фокусе).
        document.addEventListener('focusin', (e) => {
            if (state.open && e.target.matches?.(TYPED)) reveal(e.target, innerHeight - state.kb);
        });
    }
    // Клавиатура встала — поле в фокусе в видимой части своего листа (прокрутка внутри него, не страницы).
    const shown = () => {
        const el = document.activeElement;
        if (el && typing()) reveal(el, state.bottom);
    };
    // iOS: поле с клавиатурой пропало из DOM, не потеряв фокус (ответ формы подменил карточка строки, морф
    // страницы), — клавиатура уходит, а viewport остаётся ужатым: таб-бар висит посреди экрана, строки под ним
    // не рисуются. Снимаем фокус до подмены — клавиатура закрывается штатно; и после закрытия клавиатуры
    // тянем прокрутку на месте — WebKit по ней пересчитывает viewport.
    const blurInside = (target) => {
        const el = document.activeElement;
        if (el && el !== document.body && target?.contains(el) && typing()) el.blur();
    };
    document.addEventListener('turbo:submit-start', (e) => blurInside(e.target));
    document.addEventListener('turbo:before-frame-render', (e) => blurInside(e.target));
    // Морф поле сохраняет вместе с фокусом (чат, поиск) — только полная подмена страницы.
    document.addEventListener('turbo:before-render', (e) => { if (e.detail.renderMethod !== 'morph') blurInside(document.body); });
    // После клавиатуры iOS оставлял экран сдвинутым: у шелла документ не листается, но окно iOS к полю всё равно
    // двигает — возвращаем в ноль. Прокрутка «на месте» (scrollTo в ту же точку) для WebKit — не прокрутка, он ничего не
    // пересчитывает; iOS 26 после клавиатуры оставлял нажатия по fixed-листу со сдвигом (08.10.2026, начальник: «часто
    // перестают работать»). Настоящий сдвиг прокрутки страницы на 1 px и обратно заставляет его пересчитать места слоёв.
    const nudge = () => {
        if (document.getElementById('scroller') && (scrollX || scrollY)) scrollTo(0, 0);
        const box = scroller(), y = box.scrollTop;
        if (box.scrollHeight - box.clientHeight >= 1) box.scrollTop = y > 0 ? y - 1 : y + 1;
        requestAnimationFrame(() => { box.scrollTop = y; measure(); });
    };
    // Пересчёт — когда клавиатура ушла совсем (видимая часть снова во всё окно): по focusout она ещё уезжает, и сдвиг
    // посреди её ухода ничего не чинил. Последнего resize не пришло — через 700 мс.
    let open = false, gone = 0;
    const settle = () => {
        const was = open;
        measure();
        open = state.open;
        // Клавиатура снова здесь («Далее» к следующему полю) — сдвиг посреди ввода не нужен.
        if (open && gone) { clearTimeout(gone); gone = 0; }
        if (was && !open) {
            clearTimeout(gone);
            gone = setTimeout(() => { gone = 0; nudge(); }, 700);
        }
        if (gone && !open && vv.height >= innerHeight - 1) {
            clearTimeout(gone);
            gone = 0;
            setTimeout(nudge, 80);
        }
    };
    vv.addEventListener('resize', () => { settle(); requestAnimationFrame(shown); });
    vv.addEventListener('scroll', update);
    document.addEventListener('focusout', () => requestAnimationFrame(settle));
    measure();
}
