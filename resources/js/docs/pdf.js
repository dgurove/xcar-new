// PDF в шторке — pdf.js, legacy-сборка (старые iOS). Встроенный просмотр браузера не годится: в iframe на
// iPhone видна только первая страница, а ссылка открывает Quick Look, откуда приложение не выпускает.
// Страницы стопкой по ширине шторки, холсты рисуются только у видимых (экран с запасом) и освобождаются, когда
// ушли: у iOS на вкладку сотни мегабайт холстов, скан на 20 листов иначе её роняет. Холст не больше 8 Мп —
// при сильном увеличении чуть мягче, зато не падает. Рисуются по одному. Слой текста — чтобы VIN из
// текстового PDF можно было выделить и скопировать (у сканов его нет); при повороте его нет.
// Шрифты, cmaps и wasm (JBIG2 и JPEG 2000 у сканов) — /build/pdfjs, кладёт сборка (vite.config.js).
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import worker from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker&url';
import { Zoom } from './zoom';

pdfjs.GlobalWorkerOptions.workerSrc = worker;
const ASSETS = '/build/pdfjs/';
const MAX_PIXELS = 8e6;

const el = (tag, cls) => Object.assign(document.createElement(tag), { className: cls });
const free = (canvas) => { canvas.width = 0; canvas.height = 0; canvas.remove(); };

export async function pdfView(box, data, { count }) {
    const task = pdfjs.getDocument({
        data, cMapUrl: `${ASSETS}cmaps/`, cMapPacked: true, standardFontDataUrl: `${ASSETS}standard_fonts/`,
        wasmUrl: `${ASSETS}wasm/`, iccUrl: `${ASSETS}iccs/`, isEvalSupported: false, enableXfa: false,
    });
    const doc = await task.promise;
    const total = Math.min(doc.numPages, 300);
    const scroll = el('div', 'docs-scroll'), stack = el('div', 'docs-pages');
    scroll.append(stack);
    const pages = await Promise.all(Array.from({ length: total }, (_, i) => doc.getPage(i + 1)));
    const list = pages.map((page) => {
        const div = el('div', 'docs-page');
        stack.append(div);
        return { page, div, canvas: null, text: null, drawn: '', w: 0, busy: null };
    });
    const byDiv = new Map(list.map((p) => [p.div, p]));
    box.replaceChildren(scroll);
    let rot = 0, dead = false;
    const rotation = (p) => (p.page.rotate + rot) % 360;

    const layout = (z) => {
        const w = Math.max(120, scroll.clientWidth) * z;
        stack.style.width = `${w}px`;
        for (const p of list) {
            const vp = p.page.getViewport({ scale: 1, rotation: rotation(p) });
            p.w = w;
            p.div.style.width = `${w}px`;
            p.div.style.height = `${Math.round(w * vp.height / vp.width)}px`;
        }
    };

    // Очередь отрисовки: видимые по порядку, по одной.
    const visible = new Set();
    let running = false;
    const pump = async () => {
        if (running || dead) return;
        running = true;
        try {
            for (;;) {
                const next = list.find((p) => visible.has(p) && p.drawn !== stamp(p));
                if (!next || dead) break;
                await draw(next);
            }
        } finally { running = false; }
    };
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const stamp = (p) => `${Math.round(p.w)}:${rot}`;

    const draw = async (p) => {
        const want = stamp(p);
        const vp1 = p.page.getViewport({ scale: 1, rotation: rotation(p) });
        let scale = (p.w / vp1.width) * dpr;
        const px = vp1.width * vp1.height * scale * scale;
        if (px > MAX_PIXELS) scale *= Math.sqrt(MAX_PIXELS / px);
        const viewport = p.page.getViewport({ scale, rotation: rotation(p) });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        try {
            p.busy = p.page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport });
            await p.busy.promise;
        } catch (err) {
            free(canvas);
            // Отменили (ушла с экрана) — нарисуется, когда вернётся; сломанная страница — не по кругу.
            if (!(err instanceof pdfjs.RenderingCancelledException)) p.drawn = want;
            return;
        } finally { p.busy = null; }
        if (dead || !visible.has(p)) { free(canvas); return; }
        if (p.canvas) free(p.canvas);
        p.div.prepend(canvas);
        p.canvas = canvas;
        p.drawn = want;
        text(p, vp1);
    };

    const text = (p, vp1) => {
        p.text?.remove();
        p.text = null;
        if (rot !== 0) return;
        const layer = el('div', 'textLayer');
        const scale = p.w / vp1.width;
        layer.style.setProperty('--total-scale-factor', String(scale));
        p.div.append(layer);
        p.text = layer;
        new pdfjs.TextLayer({ textContentSource: p.page.streamTextContent(), container: layer, viewport: p.page.getViewport({ scale, rotation: rotation(p) }) }).render().catch(() => {});
    };

    const release = (p) => {
        p.busy?.cancel();
        if (p.canvas) free(p.canvas);
        p.text?.remove();
        Object.assign(p, { canvas: null, text: null, drawn: '' });
    };

    const watch = new IntersectionObserver((entries) => {
        for (const e of entries) {
            const p = byDiv.get(e.target);
            if (e.isIntersecting) visible.add(p);
            else { visible.delete(p); release(p); }
        }
        pump();
    }, { root: scroll, rootMargin: '100% 0px' });
    list.forEach((p) => watch.observe(p.div));

    // Номер страницы у верхней трети окна.
    let ticking = false;
    const counter = () => {
        ticking = false;
        if (total < 2) return;
        const line = scroll.scrollTop + scroll.clientHeight / 3;
        const i = list.findIndex((p) => p.div.offsetTop + p.div.offsetHeight > line);
        count(`${(i < 0 ? total - 1 : i) + 1}/${total}`);
    };
    const onScroll = () => { if (!ticking) { ticking = true; requestAnimationFrame(counter); } };
    scroll.addEventListener('scroll', onScroll, { passive: true });

    let redraw;
    const zoom = new Zoom(scroll, stack, layout, { settled: () => { clearTimeout(redraw); redraw = setTimeout(pump, 120); counter(); } });

    return {
        rotate() {
            rot = (rot + 90) % 360;
            zoom.relayout();
        },
        destroy() {
            dead = true;
            clearTimeout(redraw);
            watch.disconnect();
            zoom.destroy();
            scroll.removeEventListener('scroll', onScroll);
            list.forEach(release);
            task.destroy();
        },
    };
}
