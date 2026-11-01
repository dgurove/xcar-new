// Силуэт карточки строки (02.11.2026, владелец: заготовка «смещена — добавили кнопки, а силуэт не перерисовали»). Своей
// разметки заготовки на каждый список нет: силуэт снимается с настоящей карточки того же списка, когда она встала, — текст
// полосами по его ширине, кадры и аватары плашками их размера, кнопки и поля на своих местах. Новая кнопка в карточке сама
// попадает в силуэт со следующего открытия. Лежит в памяти и в localStorage (первое открытие после перезагрузки — тоже
// по форме); снимка ещё нет — заготовка по общей форме (detail_controller#blank).
const ghosts = new Map();
const PREFIX = 'ghost:';
const LIMIT = 60_000;
// Атрибуты, с которыми силуэт ожил бы: контроллеры, ссылки, загрузка кадров и фреймов, поля формы.
const DROP = /^(id|name|value|placeholder|href|src|srcset|sizes|action|formaction|form|for|autofocus|required|data-(controller|action|turbo.*|.*-target|.*-value|src|full|detail.*|peek|id))$/;

// Есть ли у цвета оттенок: rgb() с разными каналами или oklch() с заметной насыщенностью (так Chrome отдаёт цвета темы).
// В тени слоёв несколько (у кольца поля первый прозрачный) — смотрится каждый.
function colored(value) {
    return (value.match(/(rgba?|oklch)\([^)]*\)/g) ?? []).some((c) => {
        const n = c.match(/[\d.]+/g).map(Number);
        if (c.startsWith('oklch')) return n[1] > 0.04 && (n[3] ?? 1) > 0.1;
        return (n[3] ?? 1) >= 0.1 && Math.max(n[0], n[1], n[2]) - Math.min(n[0], n[1], n[2]) > 30;
    });
}

// Ключ — список и вид экрана: у вкладок предложений карточки разные, на телефоне и ПК раскладка своя.
export function ghostKey(wide) {
    const p = new URLSearchParams(location.search);
    return `${location.host}${location.pathname}|${p.get('preset') ?? p.get('pill') ?? p.get('tab') ?? ''}|${wide ? 'w' : 'n'}`;
}

export function ghostFor(key) {
    if (ghosts.has(key)) return ghosts.get(key);
    let ghost = null;
    try { ghost = JSON.parse(localStorage.getItem(PREFIX + key)); } catch {}
    if (ghost?.body) ghosts.set(key, ghost);
    return ghost?.body ? ghost : null;
}

// Снимок карточки: тело — то, что видно на первом экране листа (ниже не нужно, а у машины бывает 240 кадров), подвал
// («Отправить акт приёма») и сколько кнопок в полосе сверх ‹ › и «Закрыть» («Поделиться», «Открыть страницу»): полоса
// заготовки своя, живая.
export function rememberGhost(key, card) {
    const body = card.querySelector(':scope > .detail-body');
    if (!body) return;
    const foot = card.querySelector(':scope > .detail-foot');
    const ghost = {
        tools: card.querySelectorAll(':scope > .detail-bar .bar-btn:not([data-action*="detail#"])').length,
        body: ghostly(body, body.getBoundingClientRect().top - body.scrollTop),
        foot: foot?.offsetHeight ? ghostly(foot, foot.getBoundingClientRect().top) : '',
    };
    if (ghost.body.length + ghost.foot.length > LIMIT) return;
    ghosts.set(key, ghost);
    try { localStorage.setItem(PREFIX + key, JSON.stringify(ghost)); } catch {}
}

function ghostly(root, top) {
    const limit = Math.max(innerHeight, 700);
    const originals = [...root.querySelectorAll('*')];
    const clone = root.cloneNode(true);
    const copies = [...clone.querySelectorAll('*')];
    const gone = new Set();
    originals.forEach((el, i) => {
        const copy = copies[i];
        if (gone.has(copy)) return;
        const r = el.getBoundingClientRect();
        // Ниже первого экрана и невидимое (свёрнутое, display: none) — прочь; обёртки `contents` коробки не имеют, но их
        // дети видны.
        const unseen = !r.width && !r.height && el.localName !== 'br' && getComputedStyle(el).display !== 'contents';
        if (unseen || r.top - top > limit) {
            copy.remove();
            copy.querySelectorAll('*').forEach((c) => gone.add(c));
            return;
        }
        // Цветное (лаймовая кнопка, точка пройденного шага, рамка текущего поля) — серым: цвет говорит о строке, которой
        // ещё нет.
        const cs = getComputedStyle(el);
        if (colored(cs.backgroundColor)) copy.classList.add('ghost-fill');
        if ((cs.borderStyle !== 'none' && colored(cs.borderTopColor)) || colored(cs.boxShadow) || (cs.outlineStyle !== 'none' && colored(cs.outlineColor))) copy.classList.add('ghost-line');
        // Кадр, аватар, логотип — плашка их размера и скругления.
        if (['img', 'video', 'canvas', 'picture'].includes(el.localName)) {
            const box = document.createElement('i');
            box.className = 'blank ghost-box';
            box.style.cssText = `width:${r.width}px;height:${r.height}px;border-radius:${cs.borderRadius}`;
            copy.replaceWith(box);
            copy.querySelectorAll('*').forEach((c) => gone.add(c));
        }
    });
    clone.querySelectorAll('script, template, option').forEach((el) => el.remove());
    clone.querySelectorAll('*').forEach((el) => {
        for (const { name } of [...el.attributes]) if (DROP.test(name)) el.removeAttribute(name);
    });
    // Фреймы со своим адресом — простыми блоками: силуэт ничего не грузит.
    clone.querySelectorAll('turbo-frame').forEach((f) => {
        const div = document.createElement('div');
        div.className = f.className;
        div.append(...f.childNodes);
        f.replaceWith(div);
    });
    clone.querySelectorAll('textarea').forEach((t) => { t.textContent = ''; });
    // Название — пустым местом: его подставит строка, на которую нажали.
    const title = clone.querySelector('.detail-title, a.text-lg');
    if (title) {
        title.dataset.ghostTitle = '';
        title.replaceChildren();
    }
    const walker = document.createTreeWalker(clone, NodeFilter.SHOW_TEXT);
    const texts = [];
    while (walker.nextNode()) if (walker.currentNode.nodeValue.trim()) texts.push(walker.currentNode);
    texts.forEach((t) => {
        const bar = document.createElement('span');
        bar.className = 'ghost-t';
        t.replaceWith(bar);
        bar.append(t);
    });
    return clone.innerHTML;
}
