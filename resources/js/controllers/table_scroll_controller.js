import { Controller } from '@hotwired/stimulus';

// Таблица не влезает в ширину — листается вбок внутри своей плашки (владелец 04.10.2026: «это же логично»). Только
// когда не влезает: у прокручиваемой плашки липкие заголовки групп («Наличие», «Заявки») перестают липнуть.
//
// Таблица сеткой (`is-grid`, `Support\Table::gridStyle`, 29.10.2026): ширина столбца из описания — наименьшая. Не влезло в
// показанной строке (кнопка, дата с аватаром, длинный номер) — столбец шире (`--fit-<ключ>` у обёртки, ключи по порядку
// ячеек — `data-cols`), всем строкам сразу: строки — отдельные сетки и иначе разошлись бы. Строка за экраном не считается
// (`content-visibility`) — её меряем, когда подходит к экрану (`IntersectionObserver`: событие
// `contentvisibilityautostatechange` и `checkVisibility` в Safari ненадёжны — у владельца столбцы не ширились вовсе,
// 29.10.2026). Столбец только шире, не уже: пока страница открыта, он не прыгает туда-сюда при прокрутке.
const FIT_MAX = 384; // столбец не шире 24rem — длинное дальше режется «…»

export default class extends Controller {
    connect() {
        this.box = this.element.querySelector('.table-box');
        this.table = this.box?.querySelector('table');
        if (!this.table) return;
        this.observer = new ResizeObserver(() => this.sync());
        this.observer.observe(this.box);
        this.observer.observe(this.table);
        this.sync();
        if (this.element.classList.contains('is-grid')) this.fitStart();
    }

    disconnect() {
        this.observer?.disconnect();
        this.rows?.disconnect();
        this.near?.disconnect();
        cancelAnimationFrame(this.frame);
    }

    sync() {
        this.box.classList.toggle('is-scroll', this.table.offsetWidth > this.box.clientWidth + 1);
    }

    fitStart() {
        this.keys = (this.element.dataset.cols || '').split(',');
        this.fit = {};
        this.pending = new Set();
        // Строка у экрана (с запасом в экран) — меряем; дальше — пусть не считается.
        this.near = new IntersectionObserver((list) => {
            list.forEach((e) => { if (e.isIntersecting) this.pending.add(e.target); });
            if (this.pending.size) this.schedule();
        }, { rootMargin: '100% 0px' });
        // Живое обновление строки (морф, поток) — её ячейки могли стать шире.
        this.rows = new MutationObserver((list) => {
            for (const m of list) m.addedNodes.forEach((node) => node.nodeType === 1 && this.queue(node));
        });
        this.rows.observe(this.table, { childList: true, subtree: true });
        this.queueAll();
        document.fonts?.ready.then(() => this.queueAll());
    }

    queueAll() {
        // Шапка тоже: подпись со стрелкой сортировки бывает шире чисел столбца.
        this.table.querySelectorAll('thead tr, tbody tr:not(.table-group)').forEach((tr) => this.watch(tr));
    }

    // Строку меряет `near`, когда она у экрана (и сразу, если уже там): так и для новой строки живого обновления.
    watch(tr) {
        this.near.unobserve(tr);
        this.near.observe(tr);
    }

    queue(node) {
        const rows = node.localName === 'tr' ? [node] : node.closest?.('tr') ? [node.closest('tr')] : [...(node.querySelectorAll?.('tr') ?? [])];
        rows.forEach((tr) => { if (!tr.classList.contains('table-group') && tr.closest('thead, tbody')) this.watch(tr); });
    }

    schedule() {
        if (!this.frame) this.frame = requestAnimationFrame(() => { this.frame = 0; this.measure(); });
    }

    measure() {
        const rows = [...this.pending].filter((tr) => tr.isConnected);
        this.pending.clear();
        const want = {};
        for (const tr of rows) {
            [...tr.children].forEach((td, i) => {
                const key = this.keys[i];
                const short = key && td.clientWidth ? this.short(td) : 0;
                if (short > 1) want[key] = Math.max(want[key] ?? 0, Math.min(FIT_MAX, td.clientWidth + short + 1));
            });
        }
        this.apply(want);
    }

    // Сколько не хватило ячейке: ей самой или тому, что режется внутри неё (имя у аватара в «Менеджере» режется само, и
    // ячейка «влезает»).
    short(td) {
        let short = td.scrollWidth - td.clientWidth;
        for (const el of td.querySelectorAll('*')) {
            // Только то, что режет своё (overflow не visible): у прочих scrollWidth считает и вылезающее наружу (подсказка
            // логотипа вендора — 170 px).
            if (el.clientWidth && el.scrollWidth > el.clientWidth && getComputedStyle(el).overflowX !== 'visible') short = Math.max(short, el.scrollWidth - el.clientWidth);
        }
        return short;
    }

    apply(want) {
        for (const [key, width] of Object.entries(want)) {
            if (width <= (this.fit[key] ?? 0)) continue;
            this.fit[key] = width;
            this.element.style.setProperty(`--fit-${key}`, `${Math.ceil(width)}px`);
        }
    }
}
