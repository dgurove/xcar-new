// Заставка «Обновляем XCar» на время выкладки (18.11.2026, владелец: «каждый раз, когда деплою, им кажется, что сервер
// упал, всё сломалось»). Сайт и хаб событий — один контейнер, при выкладке он заменяется и секунд двадцать не отвечает.
// За пару секунд до этого `deploy.sh` зовёт `release:announce`, всем открытым окнам приходит событие `release`:
// поверх экрана встаёт заставка (модальное окно браузера — над шторками и окном машины, под ним ничего не нажать), страница под ней не трогается —
// набранное, прокрутка, открытые листы на месте. Окно само спрашивает `/up`: сайт пропал и снова ответил дважды подряд
// — «Готово», заставка уходит, поток событий открывается заново. Выкладка не состоялась (сайт так и не пропал) — уходит
// через минуту; дольше пяти минут не висит никогда: дальше обычные «Нет связи» и «Повторить».
const EVERY = 1200, CALM = 60_000, LIMIT = 300_000, MARKS = ['/images/xcar-mark.svg', '/images/xcar-mark-white.svg'];

let box = null;

export function updating() {
    document.addEventListener('live:release', start);
    // Знак — заранее: когда заставка понадобится, сервер картинку уже не отдаст.
    const warm = () => MARKS.forEach((src) => { new Image().src = src; });
    (window.requestIdleCallback ?? ((f) => setTimeout(f, 3000)))(warm);
}

async function up() {
    try {
        const r = await fetch(`/up?t=${Date.now()}`, { cache: 'no-store', credentials: 'omit', signal: AbortSignal.timeout?.(4000) });
        return r.ok;
    } catch {
        return false;
    }
}

function start() {
    if (box) return;
    const focused = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
    const keys = (e) => { e.preventDefault(); e.stopPropagation(); };
    // Модальное окно браузера: всё под ним, и открытые шторки тоже, не нажимается и не получает клавиш.
    box = document.createElement('dialog');
    box.className = 'updating';
    box.setAttribute('role', 'status');
    box.innerHTML = `
        <div class="updating-box">
            <span class="updating-mark">
                <svg class="updating-ring" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="45" pathLength="100"/></svg>
                <img class="on-light" src="${MARKS[0]}" alt="" width="44" height="44"><img class="on-dark" src="${MARKS[1]}" alt="" width="44" height="44">
            </span>
            <p class="updating-title"><span data-on="run">Обновляем XCar</span><span data-on="done">Готово</span></p>
            <p class="updating-sub">Меньше минуты, всё останется на месте</p>
        </div>`;
    // В `<html>`, не в `<body>`: переход Turbo и живой морф заставку не снимут.
    document.documentElement.append(box);
    let shown = true;
    const el = box;
    const raise = () => { try { if (el.open) el.close(); el.showModal(); } catch {} };
    raise();
    el.addEventListener('cancel', (e) => e.preventDefault());
    el.addEventListener('close', () => shown && queueMicrotask(() => shown && !el.open && raise()));
    // Шторка, открывшаяся позже (вопрос при входе), встала бы над заставкой — поднимаем заставку снова.
    const raising = setInterval(() => { if (shown && !el.contains(document.elementFromPoint(innerWidth / 2, innerHeight / 2))) raise(); }, 300);
    document.addEventListener('keydown', keys, true);
    requestAnimationFrame(() => requestAnimationFrame(() => box?.classList.add('is-open')));

    const began = Date.now();
    let down = false, oks = 0;
    const finish = (done) => {
        document.removeEventListener('keydown', keys, true);
        clearInterval(raising);
        if (done) el.classList.add('is-done');
        setTimeout(() => {
            el.classList.add('is-leaving');
            const gone = () => { shown = false; el.remove(); if (box === el) box = null; };
            el.addEventListener('transitionend', (e) => e.target === el && gone(), { once: true });
            setTimeout(gone, 900);
            // Поток событий — сразу, не дожидаясь своей паузы между попытками; поле, где печатали, — снова в фокусе.
            const topics = document.querySelector('meta[name="mercure-topics"]')?.content;
            if (done && topics) document.dispatchEvent(new CustomEvent('live:retopic', { detail: { topics } }));
            if (focused?.isConnected) focused.focus({ preventScroll: true });
        }, done ? 900 : 0);
    };
    const tick = async () => {
        const ok = await up();
        const spent = Date.now() - began;
        if (ok) oks++; else { down = true; oks = 0; }
        if (down && oks >= 2) return finish(true);
        if ((!down && spent > CALM) || spent > LIMIT) return finish(false);
        setTimeout(tick, EVERY);
    };
    setTimeout(tick, EVERY);
}
