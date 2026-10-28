import { Controller } from '@hotwired/stimulus';

// Шторка «Отдано: …» со ссылкой (x-deal.handed). Открывается сама; если «Отдать» открыло и окно письма поставщику
// (wait), — когда его закроют: две шторки разом перекрыли бы письмо. Окно так и не открылось за 3 с — сразу.
export default class extends Controller {
    static values = { wait: Boolean };

    connect() {
        // Прежняя шторка той же оболочки (второе «Отдать» подряд) — уходит.
        document.querySelectorAll('[data-controller~="handed"]').forEach((n) => { if (n !== this.element) n.remove(); });
        if (!this.waitValue) { requestAnimationFrame(() => this.show()); return; }
        const started = Date.now();
        const tick = () => {
            if (!this.element.isConnected) return;
            const letters = document.getElementById('letters');
            if (letters?.open) { letters.addEventListener('close', () => setTimeout(() => this.show(), 250), { once: true }); return; }
            if (Date.now() - started > 3000) { this.show(); return; }
            setTimeout(tick, 150);
        };
        tick();
    }

    show() {
        if (!this.element.isConnected) return;
        this.application.getControllerForElementAndIdentifier(this.element, 'sheet')?.open();
    }
}
