// Просмотр фото во весь экран — один на сайт, CRM и парковку (PhotoSwipe 5, грузится при первом открытии).
// Кадр едет за пальцем, листается и из зума, щипок и двойной тап — зум, смахивание вниз — закрыть; тап
// прячет кнопки, а не закрывает. Открытый просмотр — запись в истории: «Назад» и свайп от края закрывают
// его, а не страницу и не шторку под ним. Живёт в своём модальном <dialog>: так он встаёт поверх окна писем
// (top layer), а окно под ним не ловит ни касаний, ни Esc; шторка подтверждения открывается поверх него.
//
// Размеров кадров в базе нет: пропорцию даёт миниатюра, длинная сторона — 1600 (столько после
// PhotoIngest). Кадр, чья пропорция не сошлась с полным файлом, перерисовывается по нему.

const LONG = 1600;
const sizes = new Map(); // адрес миниатюры → { w, h }
let loaded = null;
const load = () => (loaded ??= Promise.all([import('photoswipe'), import('photoswipe/style.css')]).then(([m]) => m.default));

const path = (d) => `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
export const ICONS = {
    close: 'M6 6l12 12M18 6 6 18',
    prev: 'm15 6-6 6 6 6',
    next: 'm9 6 6 6-6 6',
    zoom: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm9 2-4-4M8 11h6M11 8v6',
    download: 'M12 4v11m0 0-4-4m4 4 4-4M5 20h14',
    eye: 'M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12Zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'eye-off': 'M3 3l18 18M10.6 5.8A10.5 10.5 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-2.6 3.4M6.6 6.6C4 8.5 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.6 0 3-.4 4.2-1M9.9 9.9a3 3 0 0 0 4.2 4.2',
    rotate: 'M20 4v6h-6M19.4 10A8 8 0 1 0 20 14',
    trash: 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6',
};

// Пропорция кадра: у загруженной миниатюры — сразу, иначе загрузить её (w320 — десятки килобайт).
function probe(url, el) {
    if (sizes.has(url)) return Promise.resolve(sizes.get(url));
    const done = (w, h) => { const s = scale(w, h); sizes.set(url, s); return s; };
    if (el?.complete && el.naturalWidth) return Promise.resolve(done(el.naturalWidth, el.naturalHeight));
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve(done(img.naturalWidth, img.naturalHeight));
        img.onerror = () => resolve(null);
        img.src = url;
    });
}

function scale(w, h) {
    const k = LONG / Math.max(w, h, 1);
    return { w: Math.round(w * k), h: Math.round(h * k) };
}

const withTimeout = (p, ms) => Promise.race([p, new Promise((r) => setTimeout(() => r(null), ms))]);

// ---- история: своя запись поверх страницы (или шторки, или окошка строки)
// Свой popstate Turbo, шторкам и окошку строки не отдаём: у window обработчики идут в порядке подписки, и
// первым подписан window.lightboxPop из <head> (x-ui.layout) — раньше любого модуля. Ключ turbo у записи
// под просмотром всё равно снимается, как у шторки (sheet.js): на случай, если «Назад» дойдёт до Turbo.

let current = null; // открытый просмотр
let inHistory = false;
let closingByBack = false;
let under;          // снятый ключ turbo записи под просмотром

window.lightboxPop = (event) => {
    if (event.state?.lightbox && !current) { event.stopImmediatePropagation(); history.back(); return; } // «Вперёд» на мёртвую запись
    if (!inHistory || event.state?.lightbox) return;
    event.stopImmediatePropagation();
    inHistory = false;
    if (under !== undefined) history.replaceState({ ...(history.state || {}), turbo: under }, '');
    under = undefined;
    if (closingByBack) { closingByBack = false; return; }
    current?.close();
};
document.addEventListener('turbo:before-cache', () => current?.destroy());
document.addEventListener('turbo:visit', () => { inHistory = false; under = undefined; });

function remember() {
    if (inHistory) return;
    const { turbo, ...rest } = history.state || {};
    under = turbo;
    history.replaceState(rest, '');
    history.pushState({ ...rest, lightbox: true }, '');
    inHistory = true;
}

function unwind() {
    if (!inHistory || !history.state?.lightbox) return;
    closingByBack = true;
    history.back();
}

/**
 * items: [{ src, thumb?, el?, download? }] — полный кадр, миниатюра (для пропорции), элемент-миниатюра
 *   на странице (кадр вырастает из него и уходит в него), адрес «Скачать» (по умолчанию src).
 * actions: [{ name, icon, title, run(index), iconFor?(index), titleFor?(index), shown?(index) }] — свои кнопки в верхней полосе.
 * download: кнопка «Скачать». onChange(index) — кадр сменился. onClose(index) — просмотр закрыт.
 * Возвращает { items, index, refresh(items, index), close(), destroy() }.
 */
export async function openLightbox({ items, index = 0, actions = [], download = false, onChange, onClose }) {
    const PhotoSwipe = await load();
    if (!items.length) return null;
    index = Math.min(Math.max(0, index), items.length - 1);

    const view = { index, items, pswp: null, closed: false, host: null };
    const data = (list) => list.map((it) => {
        const s = sizes.get(it.thumb || it.src);
        return { src: it.src, width: s?.w || LONG, height: s?.h || Math.round(LONG * .75), msrc: it.el?.currentSrc || it.thumb, element: it.el, sized: !!s, item: it };
    });
    const measure = (i) => {
        const it = view.items[i];
        if (!it || sizes.has(it.thumb || it.src)) return Promise.resolve();
        return probe(it.thumb || it.src, it.el).then((s) => {
            const pswp = view.pswp;
            if (!s || !pswp || pswp.isDestroying) return;
            const d = pswp.options.dataSource[i];
            if (d && !d.sized) { Object.assign(d, { width: s.w, height: s.h, sized: true }); pswp.refreshSlideContent(i); }
        });
    };
    const around = (i) => { for (let k = -1; k <= 2; k++) measure(((i + k) % view.items.length + view.items.length) % view.items.length); };

    const build = (list, at, animate) => {
        const pswp = new PhotoSwipe({
            dataSource: data(list),
            index: at,
            appendToEl: view.host,
            bgOpacity: 1,
            showHideAnimationType: animate && list[at]?.el ? 'zoom' : (animate ? 'fade' : 'none'),
            imageClickAction: 'zoom',
            clickToCloseNonZoomable: false,
            tapAction: 'toggle-controls',
            bgClickAction: 'close',
            wheelToZoom: true,
            loop: list.length > 2,
            returnFocus: false,
            trapFocus: false, // шторка подтверждения поверх просмотра получает фокус
            errorMsg: 'Фото не загрузилось',
            closeTitle: 'Закрыть', zoomTitle: 'Увеличить', arrowPrevTitle: 'Предыдущее', arrowNextTitle: 'Следующее',
            closeSVG: path(ICONS.close), zoomSVG: path(ICONS.zoom), arrowPrevSVG: path(ICONS.prev), arrowNextSVG: path(ICONS.next),
        });
        // Миниатюра на странице обрезана object-fit: cover — кадр вырастает из видимой части.
        pswp.addFilter('thumbEl', (el, d) => (d.element?.getClientRects().length ? d.element : null));
        pswp.addFilter('placeholderSrc', (src, content) => content.data.msrc || src);
        pswp.addFilter('itemData', (d) => {
            if (d.element) d.thumbCropped = getComputedStyle(d.element).objectFit === 'cover';
            return d;
        });
        pswp.on('uiRegister', () => {
            if (download) {
                pswp.ui.registerElement({
                    name: 'download', order: 9, isButton: true, tagName: 'a', title: 'Скачать', html: path(ICONS.download),
                    onInit: (el, p) => {
                        el.setAttribute('download', '');
                        el.target = '_blank';
                        const sync = () => { const d = p.currSlide?.data; if (d) el.href = d.item.download || d.src; };
                        p.on('change', sync);
                        sync();
                    },
                });
            }
            actions.forEach((a, k) => pswp.ui.registerElement({
                name: a.name, order: 12 + k, isButton: true, title: a.title, html: path(ICONS[a.icon] || a.icon),
                onInit: (el, p) => {
                    const sync = () => {
                        if (a.iconFor) el.innerHTML = path(ICONS[a.iconFor(p.currIndex)]);
                        if (a.titleFor) { el.title = a.titleFor(p.currIndex); el.setAttribute('aria-label', el.title); }
                        if (a.shown) el.hidden = !a.shown(p.currIndex);
                    };
                    p.on('change', sync);
                    sync();
                },
                onClick: (e, el, p) => a.run(p.currIndex),
            }));
        });
        // Пропорция по миниатюре не сошлась с полным кадром (другая обрезка, чужой файл) — перерисовать по кадру.
        pswp.on('loadComplete', ({ content }) => {
            const img = content.element;
            const d = pswp.options.dataSource[content.index];
            if (!img?.naturalWidth || !d || pswp.isDestroying) return;
            if (Math.abs(img.naturalWidth / img.naturalHeight - d.width / d.height) < .02) return;
            const s = scale(img.naturalWidth, img.naturalHeight);
            sizes.set(d.item.thumb || d.src, s);
            Object.assign(d, { width: s.w, height: s.h, sized: true });
            pswp.refreshSlideContent(content.index);
        });
        // Шторка подтверждения поверх просмотра (фокус ушёл в неё): Esc и стрелки — её.
        pswp.on('keydown', (e) => {
            const a = document.activeElement;
            if (a && a !== document.body && !view.host.contains(a)) e.preventDefault();
        });
        pswp.on('change', () => {
            view.index = pswp.currIndex;
            around(pswp.currIndex);
            onChange?.(pswp.currIndex);
        });
        pswp.on('destroy', () => { if (view.closed) { view.host?.close(); view.host?.remove(); } });
        pswp.on('close', () => {
            if (view.pswp !== pswp) return;
            view.closed = true;
            current = null;
            document.body.classList.remove('viewer-open');
            unwind();
            onClose?.(view.index);
        });
        pswp.init();
        view.pswp = pswp;
        return pswp;
    };

    // Первый кадр ждём до секунды — иначе он вырастет из пропорции 4:3 и дёрнется.
    const first = items[index];
    await withTimeout(probe(first.thumb || first.src, first.el), 1000);
    current?.destroy();
    const api = {
        get index() { return view.index; },
        get items() { return view.items; },
        close() { view.pswp?.close(); },
        destroy() {
            const p = view.pswp;
            view.pswp = null;
            view.closed = true;
            current = null;
            document.body.classList.remove('viewer-open');
            p?.destroy();
            view.host?.close();
            view.host?.remove();
        },
        // Список сменился (скрыли, повернули, удалили): пересобрать на месте, без анимации и новой записи в истории.
        refresh(list, at = view.index) {
            if (!list.length) { view.pswp?.close(); return; }
            view.items = list;
            const old = view.pswp;
            view.pswp = null;
            at = Math.min(Math.max(0, at), list.length - 1);
            view.index = at;
            probe(list[at].thumb || list[at].src, list[at].el).then(() => {
                old?.destroy();
                build(list, at, false);
                around(at);
            });
        },
    };
    current = api;
    // Esc закрывает просмотр сам (keydown PhotoSwipe), окну закрываться нечего.
    view.host = Object.assign(document.createElement('dialog'), { className: 'lightbox' });
    view.host.addEventListener('cancel', (e) => e.preventDefault());
    document.body.append(view.host);
    view.host.showModal();
    document.body.classList.add('viewer-open');
    remember();
    build(items, index, true);
    around(index);
    return api;
}
