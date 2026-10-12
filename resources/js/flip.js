// Живой морф списка (live.js → refreshPage) двигается, а не прыгает (12.10.2026, владелец: черновики из писем входят «прям
// красиво»). Перед морфом запоминаем, где стояли видимые строки и какие были числа у пилюль и групп; после — FLIP:
// - строки, что съехали (над ними встала новая), едут со старого места на новое, а не прыгают;
// - строка с тем же ключом номера (`data-flip="c:…"`), что была призраком «Не заведены» во вкладке «Из таблицы Кати»,
//   взлетает с места призрака в «Черновики» (`is-flown` вместо входа сверху `is-entering`);
// - число пилюли вкладки и группы (`[data-pill-count]`, `[data-count]`) тикает к новому, выросшее — вспыхивает
//   `is-bumped`, как пилюля, куда уехала строка из карточки (`StreamActions.count`).
// Только морф этой страницы и только видимое: на другой странице или без движения ничего не делается.
// Морф ставит класс строки с сервера и снимал бы классы идущих анимаций (вход, вспышка, отсчёт) — их возвращаем тем же
// кадром, браузер снятия не видит, и анимация не обрывается: пока едут фото, список перечитывается раз в пару секунд.
const EASE = 'cubic-bezier(.2, .8, .2, 1)';
const SHIFT = 420;
const FLY = 700;
const ROWS = '#list tr[id], #list .card[id]';
const COUNTS = '[data-pill-count], [data-count]';

const TRANSIENT = ['is-entering', 'is-flown', 'is-revealed', 'is-holding', 'is-bumped', 'arrive-counting'];

let before = null;

document.addEventListener('turbo:before-morph-attribute', (e) => {
    if (e.detail.attributeName !== 'class') return;
    const el = e.target;
    const keep = TRANSIENT.filter((name) => el.classList.contains(name));
    if (keep.length) queueMicrotask(() => el.classList.add(...keep));
});

const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
const numberOf = (el) => Number(el.textContent.replace(/\D/g, '')) || 0;
const countKey = (el) => (el.dataset.pillCount !== undefined ? `p:${el.dataset.pillCount}` : `c:${el.dataset.count}`);

document.addEventListener('turbo:before-render', (e) => {
    before = null;
    if (e.detail.renderMethod !== 'morph' || reduced()) return;
    const rows = new Map();
    const ids = new Set();
    const keys = new Map();
    const reach = innerHeight + 240;
    for (const el of document.querySelectorAll(ROWS)) {
        const top = el.getBoundingClientRect().top;
        ids.add(el.id);
        // Призрак за краем экрана (их под сотню) — строка всё равно взлетает: снизу из-за края, а не из ниоткуда.
        if (el.dataset.flip && el.classList.contains('legacy-row')) keys.set(el.dataset.flip, Math.min(Math.max(top, -80), innerHeight + 40));
        if (top < -240 || top > reach) continue;
        rows.set(el.id, top);
    }
    const counts = new Map();
    for (const el of document.querySelectorAll(COUNTS)) counts.set(countKey(el), numberOf(el));
    before = { rows, ids, keys, counts };
});

document.addEventListener('turbo:render', () => {
    if (!before) return;
    const { rows, ids, keys, counts } = before;
    before = null;
    for (const el of document.querySelectorAll(ROWS)) {
        let from = rows.get(el.id);
        const flown = !ids.has(el.id) && el.dataset.flip !== undefined && keys.has(el.dataset.flip);
        if (flown) from = keys.get(el.dataset.flip);
        if (from === undefined) continue;
        const dy = from - el.getBoundingClientRect().top;
        if (Math.abs(dy) < 1) continue;
        if (flown) {
            // Stimulus успевает подключить строку раньше: вход «сверху» у летящей не играет — волна на месте.
            el.classList.remove('is-entering');
            el.classList.add('is-flown');
            setTimeout(() => el.classList.remove('is-flown'), FLY + 1200);
        }
        el.animate([{ translate: `0 ${dy}px` }, { translate: '0 0' }], { duration: flown ? FLY : SHIFT, easing: EASE });
    }
    for (const el of document.querySelectorAll(COUNTS)) {
        const was = counts.get(countKey(el));
        const now = numberOf(el);
        if (was === undefined || was === now) continue;
        tick(el, was, now);
    }
});

/** Число бежит от прежнего к новому (не больше 12 шагов), выросшее — вспышка капсулы. */
function tick(el, from, to) {
    const steps = Math.min(12, Math.abs(to - from));
    const text = el.textContent;
    let i = 0;
    const timer = setInterval(() => {
        i++;
        el.textContent = i >= steps ? text : String(Math.round(from + ((to - from) * i) / steps));
        if (i >= steps) clearInterval(timer);
    }, 360 / steps);
    if (to < from) return;
    const pill = el.closest('a, button') ?? el;
    pill.classList.remove('is-bumped');
    void pill.offsetWidth;
    pill.classList.add('is-bumped');
    pill.addEventListener('animationend', () => pill.classList.remove('is-bumped'), { once: true });
}
