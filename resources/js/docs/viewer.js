// Содержимое просмотрщика документов (docs_controller). Тип ставит сервер (`Support\Docs`) или ссылка (`data-doc`):
// pdf — pdf.js, image — картинка с тем же масштабом, sheet и word — HTML с сервера (`?preview=1`), html — своя
// страница-документ (акт, счёт на печать) в iframe с `?embed=1`, letter — тело письма, photos — сетка кадров с
// просмотром по одному, file — карточка «Скачать». Ссылка без типа — по ответу сервера (text/html своего хоста — html).
// Бумага (PDF, скан, акт, Word) — белыми листами на сером поле с отступом; пока грузится — пустой лист.
// Каждый вид возвращает { rotate?, print?, destroy }. hooks: count(«2 из 5») — номер страницы, info({ kind, pages,
// ours, broken, lost }) — тип и объём для полосы, file(File) — скачанный файл для «Поделиться», retry() — «Повторить».
import { Zoom, PAD } from './zoom';
import { fileName } from './share';

const ATTACHMENT = /\/mail\/attachments\/\d+\/?$/;

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
            case 'photos': return photos(box, item, hooks);
            case 'letter': return await letter(box, item, hooks);
            case 'image': return image(box, item.src || item.url, hooks, item);
            case 'sheet':
            case 'word':
            case 'text': return await office(box, item, hooks);
            case 'html': return await page(box, item, hooks);
            case 'pdf': return await pdf(box, item, hooks, await fetchFile(item.url));
            case 'video': return video(box, item.url, hooks);
            case 'file': return card(box, item, hooks);
            default: return await sniff(box, item, hooks);
        }
    } catch (err) {
        box.classList.remove('docs-wait');
        // Вложение, которого нет ни на диске, ни в ящике, — повторять бесполезно; остальное — «Повторить».
        const lost = err?.status === 404 && ATTACHMENT.test(new URL(item.url, location.href).pathname);
        return card(box, item, hooks, lost ? 'Файла нет в ящике' : 'Документ не открылся', !lost);
    }
}

async function fetchFile(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw Object.assign(new Error('http'), { status: res.status });
    return res;
}

async function pdf(box, item, hooks, res) {
    hooks.info?.({ kind: 'pdf' });
    box.classList.add('docs-wait');
    const [buffer, { pdfView }] = await Promise.all([res.arrayBuffer(), import('./pdf.js')]);
    // Копия для «Поделиться» и печати — до pdf.js: он забирает буфер себе в воркер.
    const blob = new Blob([buffer], { type: 'application/pdf' });
    hooks.file?.(new File([blob], fileName(res, item.name), { type: 'application/pdf' }));
    const view = await pdfView(box, new Uint8Array(buffer), hooks);
    box.classList.remove('docs-wait');
    let frame = null, url = null;
    return {
        ...view,
        // Печать PDF — скрытой рамкой со встроенным просмотром браузера: он и печатает.
        print() {
            url ??= URL.createObjectURL(blob);
            frame?.remove();
            frame = Object.assign(el('iframe', 'docs-print'), { src: url, title: item.name });
            frame.addEventListener('load', () => {
                try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch { window.toast?.('Не получилось', 'danger'); }
            }, { once: true });
            document.body.append(frame);
        },
        destroy() {
            view.destroy();
            frame?.remove();
            if (url) URL.revokeObjectURL(url);
        },
    };
}

// Ссылка на файл без типа (почта, сделки): решает Content-Type ответа.
async function sniff(box, item, hooks) {
    const res = await fetchFile(item.url);
    const type = res.headers.get('Content-Type') || '';
    if (type.startsWith('application/pdf')) return pdf(box, item, hooks, res);
    res.body?.cancel();
    if (type.startsWith('image/') && !type.includes('svg')) return image(box, item.url, hooks, item);
    if (type.startsWith('video/')) return video(box, item.url, hooks);
    if (/spreadsheetml|wordprocessingml|text\/plain/.test(type)) return office(box, { ...item, type: type.includes('spreadsheetml') ? 'sheet' : 'word' }, hooks);
    if (type.startsWith('text/html')) return page(box, item, hooks);
    return card(box, item, hooks);
}

// Картинка (скан, фото) — листом на сером поле, масштаб раскладкой (zoom.js), поворот помнится у документа.
function image(box, src, hooks = {}, item = { name: '', type: 'image', url: src }) {
    const { rotation = 0, rotated = () => {} } = hooks;
    hooks.info?.({ kind: 'image' });
    const scroll = el('div', 'docs-scroll'), stack = el('div', 'docs-pages'), stage = el('div', 'docs-stage'), img = new Image();
    img.alt = '';
    img.decoding = 'async';
    stage.append(img);
    stack.append(stage);
    scroll.append(stack);
    box.replaceChildren(scroll);
    box.classList.add('docs-wait');
    let rot = rotation, zoom = null;
    const layout = (z) => {
        if (!img.naturalWidth) return;
        const turned = rot % 180 !== 0;
        const [w, h] = turned ? [img.naturalHeight, img.naturalWidth] : [img.naturalWidth, img.naturalHeight];
        const W = Math.max(120, scroll.clientWidth - 2 * PAD) * z, H = W * h / w;
        stack.style.padding = `${PAD * z}px`;
        stack.style.width = `${W + 2 * PAD * z}px`;
        Object.assign(stage.style, { width: `${W}px`, height: `${H}px` });
        Object.assign(img.style, { width: `${turned ? H : W}px`, height: `${turned ? W : H}px`, transform: `translate(-50%, -50%) rotate(${rot}deg)` });
    };
    img.onload = () => { box.classList.remove('docs-wait'); zoom = new Zoom(scroll, stack, layout); };
    // Картинка не пришла: у вложения письма спрашиваем, есть ли файл вообще.
    img.onerror = async () => {
        let status = 0;
        if (ATTACHMENT.test(new URL(item.url, location.href).pathname)) {
            try { status = (await fetch(item.url, { method: 'HEAD', credentials: 'same-origin' })).status; } catch {}
        }
        if (!box.isConnected) return;
        box.classList.remove('docs-wait');
        card(box, item, hooks, status === 404 ? 'Файла нет в ящике' : 'Документ не открылся', status !== 404);
    };
    img.src = src;
    return {
        rotate() { rot = (rot + 90) % 360; rotated(rot); zoom?.relayout?.(); },
        destroy() { zoom?.destroy(); img.onload = img.onerror = null; },
    };
}

async function office(box, item, hooks) {
    hooks.info?.({ kind: item.type });
    const url = new URL(item.url, location.href);
    url.searchParams.set('preview', '1');
    // Word и текст — бумага: пустой лист, пока грузится; Excel — таблица на поле интерфейса.
    const paper = item.type !== 'sheet';
    if (paper) box.classList.add('docs-wait');
    const res = await fetchFile(url);
    // Word и текст — белым листом и в тёмной теме: это бумага, а не интерфейс.
    const wrap = el('div', paper ? 'docs-html docs-paper' : 'docs-html');
    wrap.innerHTML = await res.text();
    box.classList.remove('docs-wait');
    if (paper) {
        const field = el('div', 'docs-field');
        field.append(wrap);
        box.replaceChildren(field);
    } else box.replaceChildren(wrap);
    return none;
}

// Свой документ страницей (акт приёма и выдачи, договор, счёт на печать, акт хранения): белый лист на сером поле,
// высота по содержимому — листается вся шторка. Адрес с `?embed=1`: страница прячет свою кнопку «Печать», печатает
// полоса. Шире листа (таблицы счёта) — ужимается целиком, как письмо. Масштаб — щипком, как у PDF: на телефоне
// касания идут мимо рамки (pointer-events), на компьютере текст в ней выделяется.
async function page(box, item, hooks) {
    hooks.info?.({ kind: 'html' });
    box.classList.add('docs-wait');
    const url = new URL(item.url, location.href);
    url.searchParams.set('embed', '1');
    const res = await fetchFile(url);
    // Увело на другую страницу (вход, раздел) — это не документ.
    if (res.redirected && new URL(res.url).pathname !== url.pathname) throw Object.assign(new Error('moved'), { status: 0 });
    const html = await res.text();
    const scroll = el('div', 'docs-scroll'), stack = el('div', 'docs-pages'), sheet = el('div', 'docs-page docs-page-html'), frame = el('iframe', 'docs-frame');
    frame.title = item.name;
    // Ссылки и картинки страницы — от её адреса.
    frame.srcdoc = html.replace(/<head([^>]*)>/i, `<head$1><base href="${url.href.replace(/"/g, '&quot;')}">`);
    sheet.append(frame);
    stack.append(sheet);
    scroll.append(stack);
    box.replaceChildren(scroll);
    let base = 0, tall = 0, zoom = null, watch = null, z = 1;
    const doc = () => { try { return frame.contentDocument; } catch { return null; } };
    // Ширина, в которую страница раскладывается сама (не уже листа), и её высота при этой ширине.
    const measure = () => {
        const d = doc();
        if (!d?.documentElement) return;
        const avail = Math.max(120, scroll.clientWidth - 2 * PAD);
        frame.style.width = `${avail}px`;
        const wide = d.documentElement.scrollWidth;
        base = wide > avail + 2 ? wide : avail;
        frame.style.width = `${base}px`;
        tall = Math.max(d.documentElement.scrollHeight, d.body?.scrollHeight || 0);
        frame.style.height = `${tall}px`;
        layout(z);
    };
    const layout = (k) => {
        z = k;
        if (!base) return;
        const W = Math.max(120, scroll.clientWidth - 2 * PAD) * k, s = W / base;
        stack.style.padding = `${PAD * k}px`;
        stack.style.width = `${W + 2 * PAD * k}px`;
        Object.assign(sheet.style, { width: `${W}px`, height: `${Math.ceil(tall * s)}px` });
        frame.style.transform = s === 1 ? '' : `scale(${s})`;
    };
    frame.addEventListener('load', () => {
        box.classList.remove('docs-wait');
        measure();
        zoom = new Zoom(scroll, stack, layout);
        const d = doc();
        if (!d?.body) return;
        // Картинки и шрифты дорисовались — высота другая.
        watch = new ResizeObserver(() => { const h = Math.max(d.documentElement.scrollHeight, d.body.scrollHeight); if (Math.abs(h - tall) > 1) measure(); });
        watch.observe(d.body);
        // ctrl + колесо (щипок трекпада) над листом приходит в рамку — масштаб шторки, а не страницы браузера.
        d.addEventListener('wheel', (e) => {
            if (!e.ctrlKey || !zoom) return;
            const r = frame.getBoundingClientRect(), s = r.width / base;
            zoom.wheel({ ctrlKey: true, deltaY: e.deltaY, clientX: r.left + e.clientX * s, clientY: r.top + e.clientY * s, preventDefault: () => e.preventDefault() });
        }, { passive: false });
    }, { once: true });
    const resize = new ResizeObserver(() => { if (base) measure(); });
    resize.observe(scroll);
    return {
        print() {
            try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch { window.toast?.('Не получилось', 'danger'); }
        },
        destroy() { zoom?.destroy(); watch?.disconnect(); resize.disconnect(); },
    };
}

// Тело письма — тот же фрагмент, что «Исходное письмо» в ленте (песочница iframe, высота по содержимому).
async function letter(box, item, hooks) {
    hooks.info?.({ kind: 'letter' });
    const res = await fetchFile(item.url);
    const tpl = document.createElement('template');
    tpl.innerHTML = await res.text();
    const frame = tpl.content.querySelector('[data-controller~="frame"]');
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
function photos(box, item, hooks) {
    const list = item.photos || [];
    hooks.info?.({ kind: 'photos', count: list.length });
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
        bar.append(all, el('span', 'docs-one-count', `${index + 1} из ${list.length}`));
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

// Видео с осмотра — своим плеером, во весь лист; playsinline — на айфоне не уходит во весь экран само.
function video(box, src, hooks) {
    hooks.info?.({ kind: 'video' });
    const v = el('video', 'docs-video');
    Object.assign(v, { src, controls: true, playsInline: true, preload: 'metadata' });
    box.replaceChildren(v);
    return { destroy() { v.pause(); v.removeAttribute('src'); v.load(); } };
}

// Файл, который здесь не показать или не открылся: значок типа (как в ленте писем), имя, почему, «Скачать» или
// «Повторить».
function card(box, item, hooks = {}, note = null, retry = false) {
    // Не открылся — ни поворота, ни печати; файла нет в ящике — и «Поделиться» нечем.
    hooks.info?.(note ? { kind: item.type || 'file', broken: true, lost: !retry } : { kind: 'file' });
    const wrap = el('div', 'docs-card');
    const ext = ((item.file || item.name || '').match(/\.([a-z0-9]{1,5})$/i)?.[1] || item.type || '').toLowerCase();
    const kind = ext === 'pdf' ? 'pdf' : ['xls', 'xlsx', 'csv', 'ods'].includes(ext) ? 'sheet'
        : ['doc', 'docx', 'rtf', 'odt', 'txt'].includes(ext) ? 'doc' : ['zip', 'rar', '7z', 'gz', 'tar'].includes(ext) ? 'zip' : null;
    wrap.append(el('span', `file-icon docs-card-icon${kind ? ` file-icon-${kind}` : ''}`, ext.slice(0, 4) || '?'));
    wrap.append(el('div', 'docs-card-name', item.name));
    if (note) wrap.append(el('p', 'docs-note', note));
    if (retry && hooks.retry) {
        const b = el('button', 'btn btn-quiet btn-s mt-2', 'Повторить');
        b.type = 'button';
        b.addEventListener('click', () => hooks.retry());
        wrap.append(b);
    } else if (!note) {
        const a = el('a', 'btn btn-quiet btn-s mt-2', 'Скачать');
        a.href = item.url;
        a.setAttribute('download', '');
        a.dataset.controller = 'file';
        a.dataset.action = 'file#share';
        a.dataset.fileName = item.file || item.name;
        wrap.append(a);
    }
    box.replaceChildren(wrap);
    return none;
}
