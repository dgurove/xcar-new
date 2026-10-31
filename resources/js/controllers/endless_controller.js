import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Бесконечная лента: страниц нет, следующая порция подгружается, когда до конца списка остаётся экран.
// Адрес следующей порции сервер кладёт в data-endless-next у хвоста — в нём уже и пилюля, и фильтры, и
// набранное в поиске. Ответ (тот же кусок списка) дописывается в конец, вместе с ним приезжает новый хвост.
// Таблица (`x-ui.table :portion`, 31.10.2026): ответ — та же страница со следующей сотней; из неё берётся кусок
// `tbody.table-part` своей таблицы (по id) и новый хвост, кусок встаёт следующим `tbody` — в те же столбцы. Сколько
// строк уже есть — в адрес (`rows=`): перечитывание, «Назад» и живое обновление рисуют столько же, догруженное не пропадает.
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

    // Свой хвост: у заявок парковки лента карточек (#requests) обнимает таблицу со своей догрузкой — чужой хвост не трогаем.
    tail() {
        return [...this.element.querySelectorAll('[data-endless-next]:not([data-endless-busy])')]
            .find((tail) => tail.parentElement.closest('[data-controller~="endless"]') === this.element);
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
            const html = await r.text();
            if (tail.matches('tbody')) this.part(tail, html);
            else this.append(tail, html);
        } catch {
            tail.removeAttribute('data-endless-busy');
        } finally {
            this.busy = false;
            this.watch();
        }
    }

    append(tail, html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        tail.replaceWith(...box.childNodes);
        // Лента длиннее первой порции: перечитывание страницы (возврат, живое обновление) принесло бы одну первую —
        // догруженное пропало бы, прокрутка прыгнула (app.js freshness, live.js).
        this.element.dataset.extended = '';
    }

    part(tail, html) {
        const theirs = new DOMParser().parseFromString(html, 'text/html').getElementById(this.element.id);
        const parts = theirs ? [...theirs.querySelectorAll('tbody.table-part')] : [];
        if (!parts.length) throw new Error('no rows');
        // Строка, что уже есть (список сдвинулся, пока листали: новая сверху вытолкнула строку за хвост), второй раз не встаёт.
        for (const part of parts) part.querySelectorAll('[data-detail-key]').forEach((row) => {
            if (this.element.querySelector(`[data-detail-key="${CSS.escape(row.dataset.detailKey)}"]`)) row.remove();
        });
        // Группа продолжается — второй её заголовок посреди группы не нужен (сервер рисует заголовок в начале каждой порции).
        const head = parts[0].querySelector('tr');
        const last = [...this.element.querySelectorAll('tbody.table-part tr.table-group')].at(-1);
        if (head?.matches('tr.table-group') && last && head.dataset.group === last.dataset.group) head.remove();
        tail.replaceWith(...parts, ...(theirs.querySelector('tbody[data-endless-next]') ? [theirs.querySelector('tbody[data-endless-next]')] : []));
        // Адрес без визита Turbo, ключ восстановления тот же (как `detail#address`): снимок «Назад» ляжет под этим адресом.
        const url = new URL(location.href);
        url.searchParams.set('rows', parts.at(-1).dataset.rows);
        const h = window.Turbo?.session?.history;
        if (h) h.replace(url, h.restorationIdentifier);
        else history.replaceState(history.state, '', url);
        // Свёрнутая группа (`group_fold`) прячет и догруженные строки — заголовка у них в куске может не быть.
        this.dispatch('appended');
    }
}
