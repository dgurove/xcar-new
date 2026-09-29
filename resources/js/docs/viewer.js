// Содержимое шторки документов (docs_controller). Тип ставит сервер (`Support\Docs`): pdf — pdf.js, image —
// картинка с тем же масштабом, sheet и word — HTML с сервера (`?preview=1`), letter — тело письма, photos —
// сетка кадров с просмотром по одному, file — карточка «Скачать». Ссылка без типа — по ответу сервера.
// Каждый вид возвращает { rotate?, destroy }.
import { Zoom } from './zoom';

const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
};
const none = { destroy() {} };

export async function render(box, item, hooks) {
    try {
        switch (item.type) {
            case 'photos': return photos(box, item);
            case 'letter': return await letter(box, item);
            case 'image': return image(box, item.src || item.url, hooks);
            case 'sheet':
            case 'word': return await office(box, item);
            case 'pdf': return await pdf(box, item, hooks, await fetchFile(item.url));
            case 'file': return card(box, item);
            default: return await sniff(box, item, hooks);
        }
    } catch (err) {
        return card(box, item, err?.status === 404 ? 'Файла нет в ящике' : 'Не открылся');
    }
}

async function fetchFile(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw Object.assign(new Error('http'), { status: res.status });
    return res;
}

async function pdf(box, item, hooks, res) {
    box.classList.add('docs-wait');
    const [data, { pdfView }] = await Promise.all([res.arrayBuffer(), import('./pdf.js')]);
    box.classList.remove('docs-wait');
    return pdfView(box, new Uint8Array(data), hooks);
}

// Ссылка на файл без типа (почта, сделки): решает Content-Type ответа.
async function sniff(box, item, hooks) {
    const res = await fetchFile(item.url);
    const type = res.headers.get('Content-Type') || '';
    if (type.startsWith('application/pdf')) return pdf(box, item, hooks, res);
    res.body?.cancel();
    if (type.startsWith('image/') && !type.includes('svg')) return image(box, item.url, hooks);
    if (/spreadsheetml|wordprocessingml/.test(type)) return office(box, item);
    return card(box, item);
}

function image(box, src, { rotation = 0, rotated = () => {} } = {}) {
    const scroll = el('div', 'docs-scroll'), stage = el('div', 'docs-stage'), img = new Image();
    img.alt = '';
    img.decoding = 'async';
    stage.append(img);
    scroll.append(stage);
    box.replaceChildren(scroll);
    box.classList.add('docs-wait');
    let rot = rotation, zoom = null;
    const layout = (z) => {
        if (!img.naturalWidth) return;
        const turned = rot % 180 !== 0;
        const [w, h] = turned ? [img.naturalHeight, img.naturalWidth] : [img.naturalWidth, img.naturalHeight];
        const W = Math.max(120, scroll.clientWidth) * z, H = W * h / w;
        Object.assign(stage.style, { width: `${W}px`, height: `${H}px` });
        Object.assign(img.style, { width: `${turned ? H : W}px`, height: `${turned ? W : H}px`, transform: `translate(-50%, -50%) rotate(${rot}deg)` });
    };
    img.onload = () => { box.classList.remove('docs-wait'); zoom = new Zoom(scroll, stage, layout); };
    img.onerror = () => { box.classList.remove('docs-wait'); scroll.replaceChildren(el('p', 'docs-note', 'Не открылся')); };
    img.src = src;
    return {
        rotate() { rot = (rot + 90) % 360; rotated(rot); zoom?.relayout(); },
        destroy() { zoom?.destroy(); img.onload = img.onerror = null; },
    };
}

async function office(box, item) {
    const url = new URL(item.url, location.href);
    url.searchParams.set('preview', '1');
    box.classList.add('docs-wait');
    const res = await fetchFile(url);
    const wrap = el('div', 'docs-html');
    wrap.innerHTML = await res.text();
    box.classList.remove('docs-wait');
    box.replaceChildren(wrap);
    return none;
}

// Тело письма — тот же фрагмент, что «Исходное письмо» в ленте (песочница iframe, высота по содержимому).
async function letter(box, item) {
    box.classList.add('docs-wait');
    const res = await fetchFile(item.url);
    const tpl = document.createElement('template');
    tpl.innerHTML = await res.text();
    const frame = tpl.content.querySelector('[data-controller~="frame"]');
    box.classList.remove('docs-wait');
    const wrap = el('div', 'docs-letter');
    if (item.thread) {
        const link = el('a', 'docs-thread', 'Вся переписка');
        link.href = item.thread;
        wrap.append(link);
    }
    if (frame) wrap.append(frame);
    else wrap.append(el('p', 'docs-note', 'Письмо не открылось'));
    box.replaceChildren(wrap);
    frame?.querySelector('iframe')?.addEventListener('load', (e) => fitLetter(e.target));
    return none;
}

// Письмо шире шторки (таблицы вёрстки в 600–800 px) — ужимается по ширине, а не листается вбок, как увеличенное.
function fitLetter(iframe) {
    try {
        const doc = iframe.contentDocument;
        const box = iframe.parentElement, avail = box.clientWidth, wide = doc.documentElement.scrollWidth;
        if (wide <= avail + 2) return;
        // Письмо рисуется своей шириной и ужимается целиком — как страница, а рамка берёт ужатую высоту.
        iframe.style.width = `${wide}px`;
        requestAnimationFrame(() => {
            const k = avail / wide;
            const tall = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0);
            Object.assign(iframe.style, { height: `${tall}px`, transform: `scale(${k})`, transformOrigin: '0 0' });
            box.style.height = `${Math.ceil(tall * k)}px`;
        });
    } catch {}
}

// Кадры сеткой; нажатие — кадр во всю шторку с масштабом, листается свайпом вбок и стрелками.
function photos(box, item) {
    const list = item.photos || [];
    let view = null, index = -1;
    const grid = el('div', 'docs-grid');
    list.forEach((p, i) => {
        const b = el('button', 'docs-thumb');
        b.type = 'button';
        b.setAttribute('aria-label', `Фото ${i + 1}`);
        const img = el('img');
        img.src = p.t;
        img.alt = '';
        img.loading = 'lazy';
        b.append(img);
        b.addEventListener('click', () => one(i));
        grid.append(b);
    });
    const back = () => { view?.destroy(); view = null; index = -1; box.replaceChildren(grid); };
    const one = (i) => {
        index = (i + list.length) % list.length;
        view?.destroy();
        const frame = el('div', 'docs-one');
        const bar = el('div', 'docs-one-bar');
        const all = el('button', 'docs-thread', 'Все фото');
        all.type = 'button';
        all.addEventListener('click', back);
        bar.append(all, el('span', 'docs-one-count', `${index + 1}/${list.length}`));
        const holder = el('div', 'docs-one-body');
        frame.append(bar, holder);
        box.replaceChildren(frame);
        view = image(holder, list[index].s);
        swipe(holder);
    };
    const swipe = (holder) => {
        let start = null;
        holder.addEventListener('touchstart', (e) => { start = e.touches.length === 1 ? [e.touches[0].clientX, e.touches[0].clientY] : null; }, { passive: true });
        holder.addEventListener('touchend', (e) => {
            if (!start || e.changedTouches.length !== 1) return;
            const dx = e.changedTouches[0].clientX - start[0], dy = e.changedTouches[0].clientY - start[1];
            const scroll = holder.querySelector('.docs-scroll');
            // Увеличенный кадр листается прокруткой, а не свайпом.
            if (scroll && scroll.scrollWidth > scroll.clientWidth + 2) return;
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) one(index + (dx < 0 ? 1 : -1));
        }, { passive: true });
    };
    const key = (e) => {
        if (index < 0 || e.target.closest?.('input, textarea, select')) return;
        if (e.key === 'ArrowRight') one(index + 1);
        else if (e.key === 'ArrowLeft') one(index - 1);
    };
    addEventListener('keydown', key);
    box.replaceChildren(grid);
    return {
        rotate() { view?.rotate(); },
        destroy() { view?.destroy(); removeEventListener('keydown', key); },
    };
}

function card(box, item, note) {
    const wrap = el('div', 'docs-card');
    wrap.append(el('div', 'docs-card-name', item.name));
    if (note) wrap.append(el('p', 'docs-note', note));
    const a = el('a', 'btn btn-secondary btn-s', 'Скачать');
    a.href = item.url;
    a.setAttribute('download', '');
    a.dataset.controller = 'file';
    a.dataset.action = 'file#share';
    wrap.append(a);
    box.replaceChildren(wrap);
    return none;
}
