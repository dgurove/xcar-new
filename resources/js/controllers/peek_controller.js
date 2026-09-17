import { Controller } from '@hotwired/stimulus';
import { reduce } from '../sheet';

// Таблица списка: нажатие по строке выделяет её и открывает внизу окошко
// (фрейм peek грузит карточку строки), по той же — закрывает, по другой — меняет;
// двойное нажатие — на страницу.
// Закрытие: крестик, Esc, свайп вниз по окошку, уход со страницы (Turbo снимок
// без окошка — data-turbo-temporary). ↑/↓ двигают выделение, Enter — на страницу.
export default class extends Controller {
    static targets = ['body', 'panel', 'frame'];

    connect() {
        this.onKey = (e) => { if (e.key === 'Escape' && !this.panelTarget.hidden) this.close(); };
        addEventListener('keydown', this.onKey);
    }

    disconnect() { removeEventListener('keydown', this.onKey); }

    get rows() { return [...this.bodyTarget.querySelectorAll('tr[data-peek-url]')]; }
    get current() { return this.bodyTarget.querySelector('tr[aria-selected="true"]'); }

    tap(event) {
        if (event.target.closest('a, button, form')) return;
        const row = event.target.closest('tr[data-peek-url]');
        if (!row) return;
        row === this.current ? this.close() : this.show(row);
    }

    open(event) {
        const row = event.target.closest('tr[data-href]');
        if (row && !event.target.closest('a, button, form')) window.Turbo.visit(row.dataset.href);
    }

    key(event) {
        const rows = this.rows;
        if (!rows.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const i = rows.indexOf(this.current);
            const next = rows[Math.min(rows.length - 1, Math.max(0, i + (event.key === 'ArrowDown' ? 1 : -1)))];
            if (next && next !== this.current) { event.preventDefault(); this.show(next); }
        } else if (event.key === 'Enter' && this.current && !event.target.closest('a, button')) {
            event.preventDefault();
            window.Turbo.visit(this.current.dataset.href);
        }
    }

    show(row) {
        this.current?.removeAttribute('aria-selected');
        row.setAttribute('aria-selected', 'true');
        row.focus({ preventScroll: true });
        const panel = this.panelTarget, frame = this.frameTarget;
        if (panel.hidden) { panel.hidden = false; panel.classList.remove('is-closing'); }
        if (frame.src !== new URL(row.dataset.peekUrl, location.href).href) {
            frame.innerHTML = this.skeleton ??= frame.innerHTML;
            frame.src = row.dataset.peekUrl;
        }
        row.scrollIntoView({ block: 'nearest', behavior: reduce.matches ? 'auto' : 'smooth' });
    }

    close() {
        const panel = this.panelTarget;
        if (panel.hidden) return;
        this.current?.blur();
        this.current?.removeAttribute('aria-selected');
        panel.style.translate = '';
        if (reduce.matches) { panel.hidden = true; return; }
        panel.classList.add('is-closing');
        const done = () => { panel.classList.remove('is-closing'); panel.hidden = true; };
        panel.addEventListener('animationend', done, { once: true });
        setTimeout(done, 350);
    }

    // Свайп вниз по окошку — как у шторки: за палец, отпущенный ниже трети или быстро — закрыть.
    touchStart(e) {
        if (e.touches.length !== 1) return;
        this.drag = { y: e.touches[0].clientY, at: e.timeStamp, dy: 0 };
        this.panelTarget.style.transition = 'none';
    }

    touchMove(e) {
        if (!this.drag) return;
        const y = e.touches[0].clientY - this.drag.y;
        if (y > 0) { this.drag.dy = y; this.panelTarget.style.translate = `0 ${y}px`; e.preventDefault(); }
    }

    touchEnd(e) {
        if (!this.drag) return;
        const { dy, at } = this.drag, panel = this.panelTarget;
        this.drag = null;
        panel.style.transition = '';
        if (dy > Math.min(120, panel.clientHeight / 3) || dy / Math.max(1, e.timeStamp - at) > .6) { this.close(); return; }
        panel.style.transition = 'translate var(--dur-slow) var(--ease-spring)';
        panel.style.translate = '';
        panel.addEventListener('transitionend', () => { panel.style.transition = ''; }, { once: true });
    }
}
