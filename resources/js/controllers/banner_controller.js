import { Controller } from '@hotwired/stimulus';
import { chime, unlockChime } from '../lib/chime';

// Уведомления, как в macOS и iOS: карточка с аватаром, заголовком и текстом. Разметку рисует сервер (`x-ui.banner`),
// приходит она живым каналом (live.js: notice, chat) в window.notify({id, subject, important, surface, html}).
// ПК и планшет (от 768) — стопка в правом нижнем углу, новая ближе к углу; телефон — сверху во всю ширину, старые выглядывают из-под
// новой. Обычная уходит сама через 6 с (наведение и касание держат), важная звенит и висит, пока её не нажмут, не
// закроют крестиком или не смахнут (вправо на ПК, вверх на телефоне). Карточка об объекте одна: новая о нём заменяет
// прежнюю, а открытый где угодно объект закрывает её во всех вкладках (notices-read). Закрытие в одной вкладке
// закрывает и в остальных (BroadcastChannel); важные переживают перезагрузку вкладки (sessionStorage).
// Узел постоянный (data-turbo-permanent): переходы Turbo карточки не трогают. Здесь же — число непрочитанного в
// названии вкладки (meta badge-count: уведомления и чаты).
const TTL = 6000;
const KEEP = 'xcar.banners';

export default class extends Controller {
    static targets = ['clear'];

    connect() {
        this.timers = new Map();
        this.wide = matchMedia('(min-width: 768px)');
        window.notify = (detail) => this.show(detail);
        window.noticesRead = (subjects) => this.read(subjects);
        unlockChime();
        try {
            this.channel = new BroadcastChannel('xcar-banners');
            this.channel.onmessage = (e) => e.data?.close && this.close(this.find(e.data.close), { quiet: true });
        } catch {}
        this.restore();
        this.clock = setInterval(() => this.times(), 30_000);
        this.onTitle = () => this.title();
        ['turbo:load', 'turbo:render', 'badges:updated'].forEach((name) => document.addEventListener(name, this.onTitle));
        this.titleWatch = new MutationObserver(this.onTitle);
        this.titleWatch.observe(document.head, { childList: true, subtree: true, characterData: true });
        this.title();
    }

    disconnect() {
        clearInterval(this.clock);
        this.channel?.close();
        this.titleWatch?.disconnect();
        ['turbo:load', 'turbo:render', 'badges:updated'].forEach((name) => document.removeEventListener(name, this.onTitle));
    }

    // Пришла карточка: чужого приложения — мимо (парковка отдельно от CRM и сайта), уже есть — мимо, о том же
    // объекте — заменяет прежнюю.
    show(detail, { restored = false } = {}) {
        if (!detail?.html || !this.mine(detail.surface) || this.find(detail.id)) return;
        const template = document.createElement('template');
        template.innerHTML = detail.html;
        const el = template.content.firstElementChild;
        if (!el) return;
        if (detail.subject) this.element.querySelectorAll('.banner').forEach((old) => old.dataset.subject === detail.subject && this.drop(old));
        if (restored) el.classList.add('is-restored');
        this.element.append(el);
        this.wire(el);
        this.times();
        this.layout();
        if (detail.important) {
            this.keep();
            restored || chime(detail.id);
        } else {
            this.arm(el, TTL);
        }
    }

    mine(surface) {
        const here = document.querySelector('meta[name="surface"]')?.content || 'site';
        return surface ? surface === here : here !== 'park';
    }

    find(id) {
        return id ? [...this.element.querySelectorAll('.banner')].find((el) => el.dataset.bannerId === id) : null;
    }

    read(subjects) {
        if (!subjects?.length) return;
        this.element.querySelectorAll('.banner').forEach((el) => subjects.includes(el.dataset.subject) && this.close(el, { quiet: true }));
    }

    // Закрыть все — кнопка над стопкой на ПК.
    clearAll() {
        this.element.querySelectorAll('.banner:not(.is-leaving)').forEach((el) => this.close(el));
    }

    wire(el) {
        el.querySelector('.banner-close')?.addEventListener('click', (e) => { e.preventDefault(); this.close(el); });
        // Нажатие ведёт на объект (ссылка сама, Turbo или полной загрузкой на чужой хост) и закрывает карточку.
        el.querySelector('.banner-main')?.addEventListener('click', (e) => {
            if (el.dataset.dragged) { e.preventDefault(); return; }
            this.close(el);
        });
        el.addEventListener('mouseenter', () => this.hold(el));
        el.addEventListener('mouseleave', () => this.release(el));
        this.swipe(el);
    }

    // Смахивание: на ПК вправо (мышью или двумя пальцами по тачпаду), на телефоне вверх. Не дотянули — назад.
    swipe(el) {
        let start = null;
        let moved = 0;
        el.addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || e.target.closest('.banner-close')) return;
            start = { x: e.clientX, y: e.clientY, t: Date.now() };
            moved = 0;
            delete el.dataset.dragged;
            this.hold(el);
        });
        el.addEventListener('pointermove', (e) => {
            if (!start) return;
            const d = this.wide.matches ? Math.max(0, e.clientX - start.x) : Math.min(0, e.clientY - start.y);
            if (!el.dataset.dragged && Math.abs(d) < 6) return;
            if (!el.dataset.dragged) { el.dataset.dragged = '1'; el.setPointerCapture?.(e.pointerId); el.classList.add('is-dragging'); }
            moved = d;
            el.style.translate = this.wide.matches ? `${d}px 0` : `0 ${d}px`;
            if (this.wide.matches) el.style.opacity = String(Math.max(0.2, 1 - d / 300));
        });
        const end = () => {
            if (!start) return;
            const fast = Math.abs(moved) / Math.max(1, Date.now() - start.t) > 0.5;
            start = null;
            el.classList.remove('is-dragging');
            if (el.dataset.dragged && (Math.abs(moved) > (this.wide.matches ? 90 : 36) || (fast && Math.abs(moved) > 20))) {
                this.close(el);
            } else {
                el.style.translate = '';
                el.style.opacity = '';
                this.release(el);
            }
            // Клик после перетаскивания не должен открывать объект — флажок живёт до него.
            setTimeout(() => delete el.dataset.dragged, 0);
        };
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
        let wheel = 0;
        let wheelTimer = null;
        el.addEventListener('wheel', (e) => {
            if (!this.wide.matches || Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return;
            e.preventDefault();
            wheel += Math.abs(e.deltaX);
            el.style.translate = `${Math.min(wheel, 120)}px 0`;
            clearTimeout(wheelTimer);
            if (wheel > 80) { this.close(el); return; }
            wheelTimer = setTimeout(() => { wheel = 0; el.style.translate = ''; }, 180);
        }, { passive: false });
    }

    arm(el, ms) {
        clearTimeout(this.timers.get(el));
        this.timers.set(el, setTimeout(() => this.close(el, { quiet: true }), ms));
    }

    hold(el) {
        clearTimeout(this.timers.get(el));
    }

    release(el) {
        if (!('important' in el.dataset) && el.isConnected && !el.classList.contains('is-leaving')) this.arm(el, 2500);
    }

    // Закрыть с анимацией; quiet — не сообщать другим вкладкам (они узнали сами или это истекла обычная).
    close(el, { quiet = false } = {}) {
        if (!el || el.classList.contains('is-leaving')) return;
        clearTimeout(this.timers.get(el));
        this.timers.delete(el);
        el.classList.add('is-leaving');
        el.style.translate = '';
        el.style.opacity = '';
        const gone = () => { el.remove(); this.layout(); };
        el.addEventListener('animationend', gone, { once: true });
        setTimeout(gone, 450);
        if (!quiet) this.channel?.postMessage({ close: el.dataset.bannerId });
        this.keep(el);
    }

    // Без анимации — карточку заменила новая о том же объекте.
    drop(el) {
        clearTimeout(this.timers.get(el));
        this.timers.delete(el);
        el.remove();
    }

    // Глубина в стопке: 0 — новая. На ПК видно четыре, на телефоне — новая и две под ней.
    layout() {
        const all = [...this.element.querySelectorAll('.banner:not(.is-leaving)')].reverse();
        all.forEach((el, depth) => {
            el.style.setProperty('--depth', depth);
            el.dataset.depth = depth;
            el.toggleAttribute('data-buried', depth >= (this.wide.matches ? 4 : 3));
        });
        if (all[0]) this.element.style.setProperty('--top-h', `${all[0].offsetHeight}px`);
        this.element.dataset.count = String(all.length);
        if (this.hasClearTarget) this.clearTarget.hidden = all.length < 2;
    }

    // «сейчас», «5 мин», дальше время.
    times() {
        this.element.querySelectorAll('.banner').forEach((el) => {
            const at = Date.parse(el.dataset.at || '');
            const time = el.querySelector('.banner-time');
            if (!at || !time) return;
            const min = Math.floor((Date.now() + (window.clockOffset || 0) - at) / 60_000);
            time.textContent = min < 1 ? 'сейчас' : min < 60 ? `${min} мин` : new Date(at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        });
    }

    // Важные карточки — в память вкладки, чтобы пережить полную перезагрузку.
    keep(closing = null) {
        try {
            const items = [...this.element.querySelectorAll('.banner[data-important]')].filter((el) => el !== closing && !el.classList.contains('is-leaving'))
                .map((el) => ({ id: el.dataset.bannerId, subject: el.dataset.subject || null, important: true, surface: document.querySelector('meta[name="surface"]')?.content, html: el.outerHTML.replace(/ (style|data-depth|data-buried)="[^"]*"| data-buried/g, '').replace(' is-restored', '') }));
            sessionStorage.setItem(KEEP, JSON.stringify(items));
        } catch {}
    }

    restore() {
        let items = [];
        try { items = JSON.parse(sessionStorage.getItem(KEEP) || '[]'); } catch {}
        const day = Date.now() - 86_400_000;
        items.filter((item) => Date.parse(new DOMParser().parseFromString(item.html, 'text/html').querySelector('.banner')?.dataset.at || '') > day)
            .forEach((item) => this.show(item, { restored: true }));
    }

    title() {
        const n = Number(document.querySelector('meta[name="badge-count"]')?.content || 0);
        const base = document.title.replace(/^\(\d+\+?\) /, '');
        const next = n > 0 ? `(${n > 99 ? '99+' : n}) ${base}` : base;
        if (document.title !== next) document.title = next;
    }
}
