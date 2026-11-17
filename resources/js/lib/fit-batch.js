// Общий такт для строк, что ужимают шрифт под ширину (fitline_controller, fit_controller). Раньше у каждой строки был
// свой ResizeObserver и замер «сбросить шрифт → прочитать ширину → записать»: на таблице в сотни строк это сотни
// вынужденных раскладок подряд при загрузке, повороте и подгрузке шрифта (15.10.2026, «постоянно всё лагает»). Теперь
// одна очередь за кадр: сначала все сбросы, потом все замеры, потом все записи — одна раскладка на всех.
const owners = new Map(); // наблюдаемый родитель → набор строк
const widths = new WeakMap(); // родитель → его ширина по последнему отзыву наблюдателя
const queue = new Set();
let frame = 0;

// Замер ставит строке метку «ширина родителя : длина текста» (`data-fit`). Строка с той же меткой уже подогнана — её
// не трогаем: так экран, вернувшийся из снимка («Назад», таб), не меряется вовсе (18.11.2026: «назад» в список плиток
// стоил 445 мс — сброс и замер каждой строки заставляли браузер разложить весь список, и куски за экраном тоже,
// сводя на нет их `content-visibility`). Первый замер — по наблюдателю, а не при подключении: у родителя за экраном
// коробки нет, и наблюдатель молчит, пока кусок не подъедет.
const markOf = (item, width) => `${Math.round(width)}:${item.element.textContent.length}`;

const observer = new ResizeObserver((entries) => {
    for (const entry of entries) {
        const width = entry.contentRect.width;
        widths.set(entry.target, width);
        if (!width) continue;
        owners.get(entry.target)?.forEach((item) => {
            item.width = width;
            if (item.element.dataset.fit !== markOf(item, width)) queue.add(item);
        });
    }
    if (queue.size) schedule();
});

function schedule() {
    if (!frame) frame = requestAnimationFrame(run);
}

function run() {
    frame = 0;
    const due = [...queue].filter((item) => item.element.isConnected);
    queue.clear();
    for (const item of due) if (item.element.style.fontSize) item.element.style.fontSize = '';
    const sizes = due.map((item) => item.measure());
    due.forEach((item, i) => {
        if (sizes[i]) item.element.style.fontSize = `${sizes[i]}px`;
        if (item.width) item.element.dataset.fit = markOf(item, item.width);
    });
}

// Первый замер мог пройти до загрузки Onest — по запасному шрифту, который шире.
document.fonts?.ready.then(() => { owners.forEach((set) => set.forEach((item) => queue.add(item))); schedule(); });

// item — { element, measure() → размер шрифта в px или 0 (оставить как есть) }; measure только читает.
export function watch(item) {
    const parent = item.element.parentElement;
    if (!parent) return;
    let set = owners.get(parent);
    if (!set) { set = new Set(); owners.set(parent, set); observer.observe(parent); }
    const known = set.size > 0;
    set.add(item);
    item.parent = parent;
    // В очередь строку ставит наблюдатель: он отзовётся первым же кадром, уже зная ширину родителя. Родитель уже под
    // наблюдением (вторая строка в нём) — наблюдатель промолчит, ширина берётся из его прошлого отзыва.
    const width = known ? widths.get(parent) : 0;
    if (width) {
        item.width = width;
        if (item.element.dataset.fit !== markOf(item, width)) { queue.add(item); schedule(); }
    }
}

export function unwatch(item) {
    queue.delete(item);
    const set = owners.get(item.parent);
    if (!set) return;
    set.delete(item);
    if (!set.size) { owners.delete(item.parent); observer.unobserve(item.parent); }
}
