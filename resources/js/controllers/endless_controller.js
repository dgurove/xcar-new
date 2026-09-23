import { Controller } from '@hotwired/stimulus';

// Бесконечная лента: страниц нет, следующая порция подгружается, когда до конца списка остаётся экран.
// Адрес следующей порции сервер кладёт в data-endless-next у хвоста — в нём уже и пилюля, и фильтры, и
// набранное в поиске. Ответ (тот же кусок списка) дописывается в конец, вместе с ним приезжает новый хвост.
export default class extends Controller {
    connect() {
        this.observer = new IntersectionObserver((entries) => {
            for (const entry of entries) if (entry.isIntersecting) this.load();
        }, { rootMargin: '600px 0px' });
        this.watch();
        // Список подменился (поиск, живое обновление) — следить за новым хвостом.
        this.mutations = new MutationObserver(() => this.watch());
        this.mutations.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        this.observer?.disconnect();
        this.mutations?.disconnect();
    }

    watch() {
        const tail = this.tail();
        if (!tail || tail === this.watching) return;
        this.watching = tail;
        this.observer.observe(tail);
    }

    tail() {
        return this.element.querySelector('[data-endless-next]:not([data-endless-busy])');
    }

    async load() {
        const tail = this.tail();
        if (!tail || this.busy) return;
        this.busy = true;
        tail.setAttribute('data-endless-busy', '');
        try {
            const html = await fetch(tail.dataset.endlessNext, { headers: { 'X-List': '1' } }).then((r) => r.text());
            const box = document.createElement('div');
            box.innerHTML = html;
            tail.replaceWith(...box.childNodes);
        } catch {
            tail.removeAttribute('data-endless-busy');
        } finally {
            this.busy = false;
            this.watch();
        }
    }
}
