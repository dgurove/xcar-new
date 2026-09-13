import { Controller } from '@hotwired/stimulus';

// Поиск в шторке показывает строки со второго символа, помнит последние пять
// запросов; Enter — прежний переход на полный список.
export default class extends Controller {
    static targets = ['input', 'frame', 'recent'];
    static values = { url: String };

    connect() {
        this.renderRecent();
    }

    input() {
        clearTimeout(this.timer);
        const q = this.inputTarget.value.trim();
        if (q.length < 2) { this.frameTarget.innerHTML = ''; this.renderRecent(); return; }
        this.timer = setTimeout(() => { this.frameTarget.src = `${this.urlValue}?q=${encodeURIComponent(q)}`; }, 250);
    }

    submit() {
        this.remember(this.inputTarget.value.trim());
    }

    pick(event) {
        this.inputTarget.value = event.params.q;
        this.inputTarget.form.requestSubmit();
    }

    recent() {
        try { return JSON.parse(localStorage.getItem('search:recent') || '[]'); } catch { return []; }
    }

    remember(q) {
        if (!q) return;
        const list = [q, ...this.recent().filter((x) => x !== q)].slice(0, 5);
        try { localStorage.setItem('search:recent', JSON.stringify(list)); } catch {}
    }

    renderRecent() {
        if (!this.hasRecentTarget) return;
        const list = this.recent();
        this.recentTarget.hidden = !list.length || this.inputTarget.value.trim().length >= 2;
        this.recentTarget.replaceChildren(...list.map((q) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pill';
            b.textContent = q;
            b.dataset.action = 'search#pick';
            b.dataset.searchQParam = q;
            return b;
        }));
    }
}
