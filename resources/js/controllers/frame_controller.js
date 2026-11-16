import { Controller } from '@hotwired/stimulus';

// Письмо в песочнице iframe: высота по содержимому. Письмо в закрытой шторке грузится невидимым
// (высота нулевая) — подгоняется заново, когда рамка получает ширину. Простое письмо рисуется цветами темы
// (BodyRenderer): класс dark / light у письма — тот же, что у приложения, и при смене темы на лету.
export default class extends Controller {
    static targets = ['frame'];

    connect() {
        if (this.frameTarget.contentDocument?.readyState === 'complete') this.fit();
        this.width = this.frameTarget.clientWidth;
        this.observer = new ResizeObserver(() => {
            const width = this.frameTarget.clientWidth;
            if (width > 0 && width !== this.width) { this.width = width; this.fit(); }
        });
        this.observer.observe(this.frameTarget);
        this.theme = new MutationObserver(() => this.paint());
        this.theme.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }

    disconnect() {
        this.observer?.disconnect();
        this.theme?.disconnect();
    }

    paint() {
        try {
            const root = this.frameTarget.contentDocument?.documentElement;
            const dark = document.documentElement.classList.contains('dark');
            root?.classList.toggle('dark', dark);
            root?.classList.toggle('light', !dark);
        } catch {}
    }

    fit() {
        this.paint();
        try {
            const doc = this.frameTarget.contentDocument;
            // «› Подпись» в письме раскрыли или свернули — высота заново.
            if (doc && !doc.__fitToggle) {
                doc.__fitToggle = true;
                doc.addEventListener('toggle', () => this.size(doc), true);
            }
            this.size(doc);
        } catch {
            this.frameTarget.style.height = '480px';
        }
    }

    // Высота — по низу содержимого, а не по документу: документ не ниже самой рамки, и короткое письмо («Отлично! Спасибо!»)
    // оставляло под собой пустоту до прежних 60 px (17.11.2026, в цепочке писем это дыра между письмами).
    size(doc) {
        const body = doc?.body;
        if (!body) return;
        const margin = (el) => parseFloat(getComputedStyle(el).marginBottom) || 0;
        let bottom = body.getBoundingClientRect().bottom + margin(body);
        for (const el of body.children) {
            const r = el.getBoundingClientRect();
            if (r.height > 0) bottom = Math.max(bottom, r.bottom + margin(el));
        }
        const height = Math.ceil(bottom + (doc.defaultView?.scrollY || 0));
        if (height > 0) this.frameTarget.style.height = Math.min(Math.max(height + 2, 20), 4000) + 'px';
    }
}
