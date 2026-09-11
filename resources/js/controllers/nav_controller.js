import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Стрелки ←/→ листают соседей по списку; в полях ввода не мешают.
export default class extends Controller {
    static targets = ['prev', 'next'];

    connect() {
        this.onKey = (e) => {
            if (e.metaKey || e.ctrlKey || e.altKey) return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable) return;
            const target = e.key === 'ArrowLeft' ? 'prev' : e.key === 'ArrowRight' ? 'next' : null;
            if (!target || !this[`has${target[0].toUpperCase()}${target.slice(1)}Target`]) return;
            Turbo.visit(this[`${target}Target`].href);
        };
        window.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        window.removeEventListener('keydown', this.onKey);
    }
}
