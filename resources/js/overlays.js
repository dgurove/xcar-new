// «Назад» над страницей — одним стеком (16.10.2026: до того шторки, просмотр фото, документы, карточка строки и сканер QR
// писали историю каждый сам, снимали у записи страницы ключ Turbo, держались таймерами и шимом popstate в <head>).
//
// Открытый слой (шторка, фото, документы, карточка строки на телефоне, сканер) — своя запись в истории поверх страницы:
// свайп от края и «Назад» Android закрывают его, а не страницу. Запись — копия записи страницы (с её ключом Turbo) плюс
// метка overlay { page, depth, id }. Слушатель popstate — в фазе перехвата: у window он идёт раньше обычного слушателя
// Turbo, в каком бы порядке ни подключились модули, и popstate, который касается слоёв, дальше не идёт — Turbo его не
// видит и страницу не перерисовывает. Чужие popstate (между страницами) — Turbo, как всегда.
//
// open(id, { close, guard }) — слой открыт; close() закроет его, когда запись снял «Назад» (своей анимацией, историю не
// трогает); guard() — набранное: false — уходить можно, промис — спросили человека (true — уйти, false — остаться,
// запись возвращается).
// shut(id) — слой закрыли кнопкой, жестом, Esc: его запись (и записи слоёв над ним) снимается шагом назад; промис —
// история в порядке. clear() — закрыть все перед переходом из скрипта. isOpen(id), top() — что открыто.

const stack = []; // { id, close, guard }
let page = 0;     // страница, которой принадлежат записи слоёв: Turbo-визит начинает новую
let mine = [];    // свои шаги history.go(), чьи popstate ещё не пришли: их пропускаем молча
let settled = []; // ждут, когда свои шаги дошли
let seq = 0;
const flush = () => { if (mine.length) return; const done = settled; settled = []; done.forEach((fn) => fn()); };

const mark = (depth, id) => ({ ...(history.state || {}), overlay: { page, depth, id } });
const own = (state) => (state?.overlay?.page === page ? state.overlay.depth : 0);

// Turbo переписывает верхнюю запись целиком (визит replace, адрес ?peek= у карточки строки): метка слоя при этом
// пропала бы, и шаг назад потом не нашёл бы своей записи. Переносим её — единственная правка истории мимо этого модуля.
const replace = history.replaceState.bind(history);
history.replaceState = (state, title, url) => {
    const ov = history.state?.overlay;
    replace(ov && state && typeof state === 'object' && !state.overlay ? { ...state, overlay: ov } : state, title, url);
};

addEventListener('popstate', (event) => {
    if (mine.length) {
        mine.shift();
        event.stopImmediatePropagation();
        flush();
        return;
    }
    const here = own(event.state);
    if (stack.length > here) {
        event.stopImmediatePropagation();
        const top = stack[stack.length - 1];
        // Один шаг назад со слоя, где набрано (guard вернул вопрос — промис): запись вернуть, слой оставить и спросить.
        // Вопрос уже задан (второй «Назад», пока он на экране) — запись вернуть, второй раз не спрашивать.
        if (top.asking) { history.pushState(mark(stack.length, top.id), ''); return; }
        const ask = top.guard && stack.length === here + 1 && top.guard();
        if (ask) {
            history.pushState(mark(stack.length, top.id), '');
            top.asking = true;
            ask.then((ok) => { top.asking = false; if (ok) shut(top.id).then(() => top.close()); });
            return;
        }
        for (const o of stack.splice(here).reverse()) o.close();
        return;
    }
    // «Вперёд» на запись уже закрытого слоя этой страницы — шагнуть мимо.
    if (event.state?.overlay?.page === page && here > stack.length) {
        event.stopImmediatePropagation();
        go(stack.length - here);
        return;
    }
    // Запись этой же страницы (запоздалый шаг назад от закрытого слоя) — Turbo восстанавливать нечего, а restore-визит
    // перерисовал бы экран.
    const turbo = event.state?.turbo?.restorationIdentifier;
    if (turbo && turbo === window.Turbo?.session?.history?.restorationIdentifier) event.stopImmediatePropagation();
}, { capture: true });

// Слушатель Turbo — после нашего (17.10.2026). На window popstate идёт слушателям по порядку подключения: фаза перехвата
// на самой цели их не опережает, а Turbo подключается раньше этого модуля. Шаг назад от шторки, закрытой ответом формы
// (визит replace той же страницы дал записи новый ключ Turbo), доходил до Turbo — restore-визит перечитывал страницу:
// после «Принять» пропадало окно письма вендору (flash уже прочитан). Перевешиваем его в конец очереди.
function behindTurbo() {
    const h = window.Turbo?.session?.history;
    if (!h?.started) return false;
    removeEventListener('popstate', h.onPopState, false);
    addEventListener('popstate', h.onPopState, false);
    return true;
}
if (!behindTurbo()) document.addEventListener('turbo:load', behindTurbo, { once: true });

// Свой шаг по истории: его popstate пропускается молча. WebKit бывает не присылает popstate на запись, поставленную без
// жеста, — через полсекунды ожидание снимается само (опоздавший шаг погасит проверка записи этой страницы выше).
function go(delta) {
    const step = ++seq;
    mine.push(step);
    history.go(delta);
    setTimeout(() => {
        if (!mine.includes(step)) return;
        mine = mine.filter((s) => s !== step);
        flush();
    }, 500);
}

// Визит — новая страница: записи прежних слоёв остаются в истории под ней, но уже не наши (их ключ Turbo восстановит ту
// страницу, если до них дойдут «Назад»). Перечитывание той же страницы (живое обновление морфом, возврат из фона,
// фильтр) — визит replace на тот же адрес: слои остаются на экране, и их записи — наши.
document.addEventListener('turbo:visit', (e) => {
    const same = e.detail.action === 'replace' && new URL(e.detail.url, location.href).pathname === location.pathname;
    if (same) return;
    stack.length = 0;
    page++;
});

export function open(id, { close, guard } = {}) {
    if (stack.some((o) => o.id === id)) return;
    stack.push({ id, close: close ?? (() => {}), guard });
    history.pushState(mark(stack.length, id), '');
}

export function shut(id) {
    const i = stack.findIndex((o) => o.id === id);
    if (i < 0) return Promise.resolve();
    const above = stack.splice(i);
    above.slice(1).reverse().forEach((o) => o.close());
    const steps = own(history.state) - i;
    if (steps <= 0) return Promise.resolve();
    return new Promise((resolve) => {
        settled.push(resolve);
        go(-steps);
    });
}

// Уйти со страницы из скрипта (таб-бар): слои закрыть и их записи снять, набранное — спросив. false — остались.
export async function clear() {
    for (const o of [...stack].reverse()) {
        const ask = o.guard?.();
        if (ask && !(await ask)) return false;
    }
    if (!stack.length) return true;
    const first = stack[0];
    await shut(first.id);
    first.close();
    return true;
}

export const top = () => stack[stack.length - 1]?.id ?? null;
export const isOpen = (id) => stack.some((o) => o.id === id);
