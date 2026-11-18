import { Controller } from '@hotwired/stimulus';
import { setAddress } from '../lib/address';
import { request } from '../lib/http';

// Бесконечная лента: страниц нет, следующая порция подгружается, когда до конца списка остаётся экран.
// Адрес следующей порции сервер кладёт в data-endless-next у хвоста — в нём уже и пилюля, и фильтры, и
// набранное в поиске. Ответ (тот же кусок списка) дописывается в конец, вместе с ним приезжает новый хвост.
// Таблица (`x-ui.table :portion`, 31.10.2026): ответ — та же страница со следующей сотней; из неё берётся кусок
// `tbody.table-part` своей таблицы (по id) и новый хвост, кусок встаёт следующим `tbody` — в те же столбцы. Сколько
// строк уже есть — в адрес (`rows=`): перечитывание, «Назад» и живое обновление рисуют столько же, догруженное не пропадает.
// Лента плиток без страниц (каталог и галерея сайта, 18.11.2026): в адрес пишется не больше `ROWS_MAX` плиток — столько сервер
// рисует одним ответом (`PagePart::MAX`); лента длиннее живёт в снимке страницы и помечается `data-extended` (перечитывание её
// не срежет). Дальше `AUTO_MAX` плиток сама она не растёт: хвост становится кнопкой «Ещё» — десятки тысяч узлов телефон листает
// и снимает медленно, а снимок такой страницы память разделов уже не держит (`HEAVY` в `lib/sections.js`).
const ROWS_MAX = 96;
const AUTO_MAX = 480;

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
        // Длинная лента дальше растёт по нажатию.
        if (Number(tail.dataset.rowsNext) > AUTO_MAX && !tail.matches('tbody')) { this.manual(tail); return; }
        this.observer.observe(tail);
    }

    // Свой хвост: у заявок парковки лента карточек (#requests) обнимает таблицу со своей догрузкой — чужой хвост не трогаем.
    tail() {
        return [...this.element.querySelectorAll('[data-endless-next]:not([data-endless-busy])')]
            .find((tail) => tail.parentElement.closest('[data-controller~="endless"]') === this.element);
    }

    manual(tail) {
        if (tail.dataset.endlessManual !== undefined) return;
        tail.dataset.endlessManual = '';
        tail.removeAttribute('aria-hidden');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-quiet';
        const rest = Number(tail.dataset.rest) || 0;
        button.textContent = rest ? `Ещё ${Math.min(24, rest)}, осталось ${rest}` : 'Ещё';
        button.addEventListener('click', () => this.load());
        tail.append(button);
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
        const rows = tail.dataset.rowsNext;
        tail.replaceWith(...box.childNodes);
        // Страница плиток порциями (`PagePart`): сколько плиток уже есть — в адрес (`rows=`), как у таблицы; перечитывание,
        // «Назад» и живое обновление нарисуют столько же, отметка «лента длиннее» не нужна.
        if (rows) {
            const url = new URL(location.href);
            url.searchParams.set('rows', String(Math.min(Number(rows), ROWS_MAX)));
            setAddress(url);
            // Плиток больше, чем сервер отдаст перечитыванию, — лента «длиннее первой порции», как ниже.
            if (Number(rows) > ROWS_MAX) this.element.dataset.extended = '';
            this.dispatch('appended');
            return;
        }
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
        // Адрес без визита Turbo, ключ восстановления тот же (`lib/address.js`): снимок «Назад» и раздела ляжет под этим адресом.
        const url = new URL(location.href);
        url.searchParams.set('rows', parts.at(-1).dataset.rows);
        setAddress(url);
        // Свёрнутая группа (`group_fold`) прячет и догруженные строки — заголовка у них в куске может не быть.
        this.dispatch('appended');
    }
}
