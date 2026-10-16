import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

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
        // Не ответил или ответил ошибкой — в список ничего не встаёт (страница ошибки посреди ленты), хвост ждёт следующего
        // появления на экране. Молча: без сети хвост попадается на глаза при каждой прокрутке, а «нет сети» видно и так.
        try {
            const r = await request(tail.dataset.endlessNext, { accept: 'text/html', headers: { 'X-List': '1' }, quiet: true });
            if (!r) throw new Error('not loaded');
            const box = document.createElement('div');
            box.innerHTML = await r.text();
            tail.replaceWith(...box.childNodes);
        } catch {
            tail.removeAttribute('data-endless-busy');
        } finally {
            this.busy = false;
            this.watch();
        }
    }
}
