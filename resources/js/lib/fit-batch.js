// Общий такт для строк, что ужимают шрифт под ширину (fitline_controller, fit_controller). Раньше у каждой строки был
// свой ResizeObserver и замер «сбросить шрифт → прочитать ширину → записать»: на таблице в сотни строк это сотни
// вынужденных раскладок подряд при загрузке, повороте и подгрузке шрифта (15.10.2026, «постоянно всё лагает»). Теперь
// одна очередь за кадр: сначала все сбросы, потом все замеры, потом все записи — одна раскладка на всех.
const owners = new Map(); // наблюдаемый родитель → набор строк
const queue = new Set();
let frame = 0;

const observer = new ResizeObserver((entries) => {
    for (const entry of entries) owners.get(entry.target)?.forEach((item) => queue.add(item));
    schedule();
});

function schedule() {
    if (!frame) frame = requestAnimationFrame(run);
}

function run() {
    frame = 0;
    const due = [...queue].filter((item) => item.element.isConnected);
    queue.clear();
    for (const item of due) item.element.style.fontSize = '';
    const sizes = due.map((item) => item.measure());
    due.forEach((item, i) => { if (sizes[i]) item.element.style.fontSize = `${sizes[i]}px`; });
}

// Первый замер мог пройти до загрузки Onest — по запасному шрифту, который шире.
document.fonts?.ready.then(() => { owners.forEach((set) => set.forEach((item) => queue.add(item))); schedule(); });

// item — { element, measure() → размер шрифта в px или 0 (оставить как есть) }; measure только читает.
export function watch(item) {
    const parent = item.element.parentElement;
    if (!parent) return;
    let set = owners.get(parent);
    if (!set) { set = new Set(); owners.set(parent, set); observer.observe(parent); }
    set.add(item);
    item.parent = parent;
    queue.add(item);
    schedule();
}

export function unwatch(item) {
    queue.delete(item);
    const set = owners.get(item.parent);
    if (!set) return;
    set.delete(item);
    if (!set.size) { owners.delete(item.parent); observer.unobserve(item.parent); }
}
