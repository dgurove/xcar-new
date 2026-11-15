import { Controller } from '@hotwired/stimulus';

// Шторка «Отдано: …» со ссылкой (x-deal.handed). Открывается сама; если «Отдать» открыло и окно письма поставщику
// (wait), — когда его закроют: две шторки разом перекрыли бы письмо. Окно так и не открылось за 3 с — сразу.
// Окно письма закрылось, а письмо не ушло и «Без письма» не нажали (15.11.2026, начальник неделю думал, что письма
// уходят, — окно у него закрывалось само) — в шторке строка «Письмо поставщику не ушло» и «Написать письмо».
export default class extends Controller {
    static values = { wait: Boolean };
    static targets = ['letter'];

    connect() {
        // Прежняя шторка той же оболочки (второе «Отдать» подряд) — уходит.
        document.querySelectorAll('[data-controller~="handed"]').forEach((n) => { if (n !== this.element) n.remove(); });
        if (!this.waitValue) { requestAnimationFrame(() => this.show()); return; }
        const started = Date.now();
        const tick = () => {
            if (!this.element.isConnected) return;
            const letters = document.getElementById('letters');
            if (letters?.open) {
                addEventListener('letters:closed', (e) => {
                    const { handled, url } = e.detail ?? {};
                    if (!handled && /\/letter-ask|\/work\/mail\/new/.test(url ?? '') && this.hasLetterTarget) {
                        this.letterUrl = url;
                        this.letterTarget.hidden = false;
                    }
                    setTimeout(() => this.show(), 250);
                }, { once: true });
                return;
            }
            if (Date.now() - started > 3000) { this.show(); return; }
            setTimeout(tick, 150);
        };
        tick();
    }

    show() {
        if (!this.element.isConnected) return;
        this.application.getControllerForElementAndIdentifier(this.element, 'sheet')?.open();
    }

    // «Написать письмо» — шторка уходит, окно письма открывается снова на том же месте.
    letter() {
        const url = this.letterUrl;
        this.application.getControllerForElementAndIdentifier(this.element, 'sheet')?.close();
        if (url) setTimeout(() => window.dispatchEvent(new CustomEvent('letters:open', { detail: { url } })), 300);
    }
}
