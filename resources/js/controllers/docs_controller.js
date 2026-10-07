import { Controller } from '@hotwired/stimulus';
import { reduce, sheetInHistory } from '../sheet';
import { known, shareFile, warm } from '../docs/share';

// Шторка документов (x-ui.docs, одна на страницу в x-ui.shell): скан заявки, СТС, Excel, письмо, фото, акт —
// внутри интерфейса, а не в Quick Look, из которого установленное приложение на iPhone не выпускает.
// Не модальная: поля под ней заполняются, пока смотришь документ. Ссылка внутри модального окна (окно писем,
// шторка «Оплатить», «···» счёта, «Поделиться») открывает её модально поверх этого окна (`showModal` — верхний
// слой, живая), вкладки — документы того же окна; Esc и «Закрыть» закрывают сначала её.
// Телефон — лист снизу: высота тянется за шапку (точки 30/55/88 % экрана, ниже нижней — закрыть), страница
// получает отступ на его высоту, поле с фокусом выезжает над листом, с клавиатурой лист ужимается и потом
// возвращается. «Назад» закрывает лист, а не страницу: своя запись в истории, как у шторок (sheet.js) и фото.
// От 1024 — панель справа во всю высоту, ширина тянется за левый край, страница сдвигается.
// Открывает любая ссылка `a[data-doc]` (x-ui.doc) и любая ссылка на файл (`/files/{id}`,
// `…/mail/attachments/{id}`) без download; вкладки — все документы страницы по порядку, без повторов.
// Рисует ../docs/viewer.js (pdf.js, картинка, Excel и Word с сервера, свой HTML-документ, письмо, фото) —
// отдельным куском. Полоса — имя документа (длинное — многоточием посередине) и под ним тип с объёмом; справа
// ✨, поворот (фото и сканы), «Поделиться» на телефоне (лист с самим файлом, docs/share.js; HTML-документ —
// печать, в ней «Поделиться» системы) или «Скачать» и «Печать» на компьютере, закрыть.
// Высота и ширина помнятся (localStorage); форма, отправленная при открытой шторке, вернётся на ту же
// страницу — шторка откроется снова на том же документе. `a[data-doc-auto]` открывается сам.
// ✨ — открытое вложение письма (скан, фото) в окно «Распознать» предмета страницы (`[data-scan-subject]` у
// x-mail.scan-button): окно сразу читает этот файл (`?only=`); в редакторе с читалкой «Завести» (data-scan-reader) —
// файл дописывается в её чтение, поля заполнятся по ходу.
const FILE = /^\/(?:files|(?:[\w-]+\/)*mail\/attachments)\/\d+\/?$/;
const ATTACHMENT = /\/mail\/attachments\/(\d+)\/?$/;
const PAPER = /^\/files\/(\d+)\/?$/;
const SNAPS = [.3, .55, .88];
const wide = matchMedia('(min-width: 1024px)');
const coarse = matchMedia('(pointer: coarse)');
const KINDS = { pdf: 'PDF', image: 'Фото', sheet: 'Excel', word: 'Word', text: 'Текст', html: 'Документ', video: 'Видео', letter: 'Письмо' };
let seq = 0;
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch {} },
};
const html = document.documentElement;

export default class extends Controller {
    static targets = ['tabs', 'body', 'count', 'rotate', 'download', 'print', 'scan', 'name', 'meta'];

    connect() {
        this.items = [];
        this.token = 0;
        this.dead = new Set();
        this.onClick = (e) => this.intercept(e);
        this.onFocus = (e) => this.focused(e);
        this.onSubmit = () => this.rememberOpen();
        // Уход со страницы (визит) закрывает шторку до снимка Turbo; снимок без визита — это «Назад» со шторки над
        // ней (запись страницы без ключа Turbo), шторка остаётся.
        this.onVisit = () => { this.leaving = true; if (this.entry) this.dead.add(this.entry); this.entry = null; };
        this.onLoad = () => { this.leaving = false; };
        this.onCache = () => { if (this.leaving) this.close(true); };
        this.onPop = (e) => this.popped(e);
        this.onHostClose = () => { if (this.shown && this.host) this.close(true); };
        // «Подставить» в окне «Распознать» перечитывает страницу на месте (docs:keep перед этим) — тот же документ снова.
        this.onKeep = () => { this.kept = this.shown && !this.host && this.items[this.index] ? this.items[this.index].key : null; };
        this.onMorph = () => {
            const key = this.kept;
            this.kept = null;
            const a = key && this.links().find((l) => this.key(l) === key);
            if (a) this.open(a, { instant: true });
        };
        this.onViewport = () => this.fit();
        this.onWide = () => this.relayout();
        this.onMove = (e) => this.dragMove(e);
        this.onUp = (e) => this.dragEnd(e);
        this.kb = html.classList.contains('kb-open');
        this.kbWatch = new MutationObserver(() => this.keyboard());
        this.kbWatch.observe(html, { attributes: true, attributeFilter: ['class'] });
        document.addEventListener('click', this.onClick);
        document.addEventListener('focusin', this.onFocus);
        document.addEventListener('turbo:submit-start', this.onSubmit);
        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:load', this.onLoad);
        document.addEventListener('turbo:before-cache', this.onCache);
        document.addEventListener('turbo:morph', this.onMorph);
        window.addEventListener('docs:keep', this.onKeep);
        // Раньше Turbo и sheet.js, после просмотра фото: зовёт обработчик из <head> (x-ui.layout) — у window popstate
        // идёт по порядку подписки, а контроллер подключается позже Turbo.
        window.docsPop = this.onPop;
        window.visualViewport?.addEventListener('resize', this.onViewport);
        addEventListener('resize', this.onViewport);
        wide.addEventListener('change', this.onWide);
        const start = () => this.restore();
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start, { once: true }) : setTimeout(start);
    }

    disconnect() {
        this.kbWatch.disconnect();
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('focusin', this.onFocus);
        document.removeEventListener('turbo:submit-start', this.onSubmit);
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:load', this.onLoad);
        document.removeEventListener('turbo:before-cache', this.onCache);
        document.removeEventListener('turbo:morph', this.onMorph);
        window.removeEventListener('docs:keep', this.onKeep);
        if (window.docsPop === this.onPop) window.docsPop = null;
        window.visualViewport?.removeEventListener('resize', this.onViewport);
        removeEventListener('resize', this.onViewport);
        wide.removeEventListener('change', this.onWide);
        this.host?.removeEventListener('close', this.onHostClose);
        clearTimeout(this.countTimer);
        this.view?.destroy();
        this.view = null;
        html.classList.remove('docs-open');
        html.style.removeProperty('--docs-h');
        html.style.removeProperty('--docs-w');
    }

    // После сохранения формы — тот же документ; иначе тот, что страница просит открыть сразу.
    restore() {
        let again = null;
        try {
            again = JSON.parse(sessionStorage.getItem('docs:again') || 'null');
            sessionStorage.removeItem('docs:again');
        } catch {}
        const links = this.links();
        if (again && again.path === location.pathname && Date.now() - again.at < 120000) {
            const a = links.find((l) => this.key(l) === again.key);
            if (a) { this.open(a, { instant: true }); return; }
        }
        const auto = links.find((l) => l.hasAttribute('data-doc-auto'));
        if (auto) this.open(auto, { auto: true });
    }

    rememberOpen() {
        if (!this.shown || this.host || !this.items[this.index]) return;
        try { sessionStorage.setItem('docs:again', JSON.stringify({ path: location.pathname, key: this.items[this.index].key, at: Date.now() })); } catch {}
    }

    get shown() { return this.element.open; }

    // Документы страницы (вне окон) или открытого модального окна, из которого открыли.
    links(host = this.host) {
        if (host) return [...host.querySelectorAll('a[data-doc]')].filter((a) => a.dataset.doc !== 'off');
        return [...document.querySelectorAll('a[data-doc]')].filter((a) => a.dataset.doc !== 'off' && !this.element.contains(a) && !a.closest('dialog'));
    }

    key(a) {
        if (a.dataset.doc === 'photos') return '#photos';
        // Файлы одного архива различаются только `?entry=N`, выгрузки одного адреса (Excel и PDF закупки) — `?format=`.
        const url = new URL(a.href, location.href);
        const own = ['entry', 'format'].filter((k) => url.searchParams.has(k)).map((k) => `${k}=${url.searchParams.get(k)}`);
        return url.pathname + (own.length ? `?${own.join('&')}` : '');
    }

    item(a) {
        let photos = null;
        try { photos = a.dataset.docPhotos ? JSON.parse(a.dataset.docPhotos) : null; } catch {}
        return {
            key: this.key(a), url: a.href, type: a.dataset.doc || '', photos,
            name: a.dataset.docName || a.title || a.textContent.trim() || 'Файл', file: a.title || '',
            src: a.dataset.docSrc || null, thread: a.dataset.docThread || null,
        };
    }

    intercept(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('download') || a.dataset.doc === 'off' || this.element.contains(a)) return;
        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin || !('doc' in a.dataset || FILE.test(url.pathname))) return;
        e.preventDefault();
        // Ссылка в модальном окне (окно писем, «Оплатить») — шторка модально поверх этого окна.
        this.open(a, { host: a.closest('dialog:modal') });
    }

    // auto — открылся сам (скан на разборе письма): не выше середины, чтобы поля под ним были видны.
    // host — модальное окно, из которого открыли: шторка над ним, вкладки — его документы.
    open(a, { instant = false, auto = false, host = null } = {}) {
        // Была открыта в другом слое (на странице, а теперь из окна над ней) — заново в нужном.
        if (this.shown && host !== this.host) this.close(true, false);
        this.host = host;
        const seen = new Set();
        this.items = [];
        for (const link of [...this.links(), a]) {
            const item = this.item(link);
            if (seen.has(item.key)) continue;
            seen.add(item.key);
            this.items.push(item);
        }
        // Фото из писем — одной вкладкой «Фото N» с сеткой, а не вкладкой на каждый кадр (в ящике ТС их бывает под сотню).
        let start = this.items.findIndex((item) => item.key === this.key(a));
        const shots = this.items.filter((item) => item.type === 'image' && /\/attachments\/\d+/.test(new URL(item.url).pathname));
        if (shots.length > 1) {
            const first = this.items.indexOf(shots[0]);
            const opened = shots.indexOf(this.items[start]);
            const pic = (item, p) => { const u = new URL(item.url); u.search = ''; u.searchParams.set(p, '1'); return u.href; };
            const group = {
                key: '#mail-photos', url: '#photos', type: 'photos', name: `Фото ${shots.length}`, file: '',
                photos: shots.map((item) => ({ t: pic(item, 'thumb'), s: pic(item, 'large') })), start: opened >= 0 ? opened : null,
            };
            this.items = this.items.filter((item) => !shots.includes(item));
            this.items.splice(first, 0, group);
            start = opened >= 0 ? first : this.items.findIndex((item) => item.key === this.key(a));
        }
        this.tabsTarget.replaceChildren(...this.items.map((item, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'docs-tab';
            b.textContent = item.name;
            b.title = item.name;
            b.dataset.action = 'docs#pick';
            b.dataset.index = i;
            return b;
        }));
        this.tabsTarget.hidden = this.items.length < 2;
        this.reveal(instant);
        if (auto && !wide.matches) this.setHeight(Math.min(this.height, this.snaps()[1]));
        this.show(start);
    }

    pick(event) {
        const i = Number(event.currentTarget.dataset.index);
        if (i !== this.index) this.show(i);
    }

    async show(i) {
        const item = this.items[i];
        if (!item) return;
        this.index = i;
        [...this.tabsTarget.children].forEach((b, n) => b.toggleAttribute('aria-current', n === i));
        this.tabsTarget.children[i]?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
        clearTimeout(this.countTimer);
        this.countTarget.textContent = '';
        this.countTarget.classList.remove('is-shown');
        this.title(item.name);
        this.scanTarget.hidden = !this.scanUrl(item);
        this.downloadTarget.href = item.url;
        this.describe({ kind: item.type || null });
        // Небольшой файл (Excel, Word, текст) телефон берёт сразу — «Поделиться» откроет лист в том же жесте.
        if (coarse.matches && ['sheet', 'word', 'text'].includes(item.type)) warm(item.url, item.file || item.name).catch(() => {});
        const token = ++this.token;
        this.view?.destroy();
        this.view = null;
        const box = document.createElement('div');
        box.className = 'docs-view';
        this.bodyTarget.replaceChildren(box);
        const { render } = await (this.viewer ??= import('../docs/viewer.js'));
        if (token !== this.token) return;
        // Поворот помнится у документа: скан, пришедший боком, второй раз поворачивать не надо.
        const turn = `docs:rot:${item.key}`;
        const mine = (fn) => (...args) => { if (token === this.token) fn(...args); };
        const view = await render(box, item, {
            count: mine((text) => this.pageCount(text)),
            info: mine((info) => this.describe(info)),
            file: (file) => known(item.url, file),
            retry: mine(() => this.show(i)),
            rotation: Number(store.get(turn)) || 0,
            rotated: (deg) => store.set(turn, String(deg)),
        });
        if (token !== this.token) { view?.destroy(); return; }
        this.view = view;
    }

    // Имя в полосе: длинное режется посередине — хвост (номер, дата в имени файла) виден всегда.
    title(name) {
        const head = document.createElement('span'), tail = document.createElement('span');
        head.className = 'docs-name-head';
        tail.className = 'docs-name-tail';
        const cut = name.length > 18 ? 8 : 0;
        head.textContent = cut ? name.slice(0, -cut) : name;
        tail.textContent = cut ? name.slice(-cut) : '';
        this.nameTarget.replaceChildren(head, tail);
        this.nameTarget.title = name;
    }

    // Тип и объём под именем («PDF, 3 стр.», «Фото», «Excel») и кнопки полосы по тому, что открылось: поворот — у
    // фото и сканов (не у своих счетов и актов), печать — PDF и свой документ на компьютере.
    describe({ kind, pages, count, ours, broken, lost } = {}) {
        this.kind = kind;
        const item = this.items[this.index] ?? {};
        const ext = (item.file || '').match(/\.([a-z0-9]{1,5})$/i)?.[1]?.toLowerCase();
        let text = kind === 'photos' ? `${count ?? item.photos?.length ?? ''} фото`.trim()
            : kind === 'file' ? (ext ? ext.toUpperCase() : 'Файл')
            : kind === 'sheet' && ext === 'csv' ? 'CSV' : KINDS[kind] || '';
        if (kind === 'pdf' && pages) text += `, ${pages} стр.`;
        // «Письмо» под «Письмом» ничего не говорит.
        if (text === item.name) text = '';
        this.metaTarget.textContent = text;
        this.rotateTarget.hidden = broken || !(kind === 'image' || (kind === 'pdf' && !ours));
        const file = kind && !['letter', 'photos'].includes(kind);
        const phone = coarse.matches;
        this.downloadTarget.hidden = !file || lost || (kind === 'html' && (broken || !phone));
        this.downloadTarget.setAttribute('aria-label', phone ? 'Поделиться' : 'Скачать');
        this.downloadTarget.title = phone ? 'Поделиться' : 'Скачать';
        this.printTarget.hidden = phone || broken || !['pdf', 'html'].includes(kind);
        if (lost) this.scanTarget.hidden = true;
    }

    // Номер страницы капсулой внизу: виден, пока листают, гаснет через полторы секунды.
    pageCount(text) {
        const c = this.countTarget;
        c.textContent = text;
        c.classList.add('is-shown');
        clearTimeout(this.countTimer);
        this.countTimer = setTimeout(() => c.classList.remove('is-shown'), 1500);
    }

    rotate() {
        this.view?.rotate?.();
    }

    // «Поделиться» на телефоне — лист с самим файлом; свой HTML-документ файла не имеет — печать, в ней «Поделиться»
    // системы. На компьютере ссылка просто скачивает (download).
    share(event) {
        if (!coarse.matches) return;
        event.preventDefault();
        const item = this.items[this.index];
        if (!item) return;
        if (this.kind === 'html') { this.view?.print?.(); return; }
        shareFile(item.url, item.file || item.name);
    }

    warm() {
        const item = this.items[this.index];
        if (coarse.matches && item && this.kind !== 'html') warm(item.url, item.file || item.name).catch(() => {});
    }

    print() {
        this.view?.print?.();
    }

    // Адрес окна «Распознать» с этим файлом: вложение письма (не файл из архива) или документ предложения (`m45`,
    // если предмет их читает — data-scan-papers), скан или фото, и на странице есть предмет с ✨ — вне окон (окно
    // «Распознать» открывается и над шторкой, модальной над окном писем).
    scanUrl(item) {
        const target = this.scanFile(item);
        return target ? `${target.button.dataset.scanSubject}?only=${target.id}` : null;
    }

    // Предмет страницы с ✨ и номер файла для него; читалка «Завести» (data-scan-reader) берёт файл в своё чтение.
    scanFile(item) {
        if (!['pdf', 'image'].includes(item.type)) return null;
        const url = new URL(item.url, location.href);
        const button = [...document.querySelectorAll('[data-scan-subject]')].find((b) => !b.closest('dialog'));
        const paper = button?.hasAttribute('data-scan-papers') && url.pathname.match(PAPER)?.[1];
        const id = paper ? `m${paper}` : !url.searchParams.has('entry') && url.pathname.match(ATTACHMENT)?.[1];
        return id && button ? { button, id } : null;
    }

    scan() {
        const target = this.scanFile(this.items[this.index] ?? {});
        if (!target) return;
        if (target.button.hasAttribute('data-scan-reader')) {
            target.button.dispatchEvent(new CustomEvent('reader:add', { detail: { id: target.id } }));
            window.toast?.('Файл в чтении');
        } else {
            window.dispatchEvent(new CustomEvent('scan:open', { detail: { url: `${target.button.dataset.scanSubject}?only=${target.id}` } }));
        }
    }

    reveal(instant) {
        if (this.shown) return;
        const el = this.element;
        el.classList.remove('is-closing');
        el.classList.toggle('is-instant', instant || reduce.matches);
        el.classList.toggle('docs-modal', !!this.host);
        if (this.host) {
            // Верхний слой над окном: живая, окно под ней не ловит ни касаний, ни Esc. Закрыли окно — и её.
            el.showModal();
            if (matchMedia('(hover: none)').matches && document.activeElement?.matches('button, a')) document.activeElement.blur();
            this.host.addEventListener('close', this.onHostClose, { once: true });
        } else {
            // Не модальная: open без showModal — фокус остаётся в поле, страница живая.
            el.setAttribute('open', '');
            html.classList.add('docs-open');
        }
        this.relayout();
        this.remember();
    }

    relayout() {
        if (!this.shown) return;
        if (wide.matches) this.setWidth(this.wanted());
        else this.setHeight(this.startHeight());
    }

    // instant — без анимации (уход со страницы, смена слоя); unwind — снять свою запись в истории, если она сверху.
    close(instant = false, unwind = true) {
        if (!this.shown || this.element.classList.contains('is-closing')) return;
        this.token++;
        if (unwind) this.forget();
        else if (this.entry) { this.dead.add(this.entry); this.entry = null; }
        // Ждём конца анимации или 400 мс — что раньше; второе снимается. Иначе оставшийся слушатель animationend
        // ловил конец анимации следующего открытия и тут же закрывал шторку: второй документ из «···» не открывался.
        let timer;
        const done = () => {
            this.element.removeEventListener('animationend', done);
            clearTimeout(timer);
            if (!this.shown) return;
            this.element.classList.remove('is-closing');
            this.element.close();
            this.closed();
        };
        if (instant === true || reduce.matches) { done(); return; }
        this.element.classList.add('is-closing');
        this.element.addEventListener('animationend', done);
        timer = setTimeout(done, 400);
    }

    // Диалог закрылся (своей кнопкой или чужим closeSheet — переход по ссылке из модальной шторки): убрать вид.
    // Событие close приходит задачей позже — к тому времени шторку могли открыть снова.
    closed() {
        if (this.shown) return;
        this.token++;
        this.element.classList.remove('is-closing', 'docs-modal');
        html.classList.remove('docs-open');
        this.host?.removeEventListener('close', this.onHostClose);
        this.host = null;
        if (this.entry) { this.dead.add(this.entry); this.entry = null; }
        clearTimeout(this.countTimer);
        this.view?.destroy();
        this.view = null;
        this.bodyTarget.replaceChildren();
    }

    // Esc — не поверх фото во весь экран и не поверх шторки-диалога; модальную закрывает cancel.
    escape(event) {
        if (!this.shown || this.host || document.querySelector('dialog:modal')) return;
        event.preventDefault();
        this.close();
    }

    cancel(event) {
        event.preventDefault();
        this.close();
    }

    // Модальная: нажатие мимо листа (по окну под ней) закрывает её.
    backdrop(event) {
        if (!this.host || event.target !== this.element) return;
        const r = this.element.getBoundingClientRect();
        if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) this.close();
    }

    // ——— «Назад» на телефоне: своя запись в истории поверх страницы (или окна, над которым шторка)

    remember() {
        if (this.entry || wide.matches) return;
        this.entry = `docs-${Date.now()}-${++seq}`;
        history.pushState({ ...(history.state || {}), docs: this.entry }, '');
    }

    // Закрыли кнопкой, Esc или жестом: своя запись сверху — шаг назад молча.
    forget() {
        const entry = this.entry;
        if (!entry) return;
        this.entry = null;
        this.dead.add(entry);
        if (history.state?.docs === entry) { this.skip = true; history.back(); }
    }

    popped(e) {
        if (this.skip) { this.skip = false; e.stopImmediatePropagation(); return; }
        const at = e.state?.docs;
        if (this.entry && at === this.entry) {
            // Вернулись на запись шторки со шторки или фото над ней — это их «Назад». Turbo и sheet.js перепишут
            // запись — пометить снова, чтобы «Назад» потом сняло шторку.
            setTimeout(() => { if (this.entry === at && history.state?.docs !== at) history.replaceState({ ...(history.state || {}), docs: at }, ''); });
            return;
        }
        if (this.entry) {
            // «Назад» снял запись открытой шторки: закрыть её, дальше событие не идёт (Turbo не перерисует страницу).
            e.stopImmediatePropagation();
            const host = this.host;
            this.dead.add(this.entry);
            this.entry = null;
            this.close(false, false);
            // Окно под шторкой само уходит (его закрыли, оно снимает свою запись) — пропустить и её, к sheet.js.
            if (host && (!host.open || host.classList.contains('is-closing'))) history.back();
            return;
        }
        // Запись уже закрытой шторки этой страницы («Вперёд» или «Назад» с окна над ней) — шагнуть мимо. Окно над
        // ней снимает sheet.js — сначала он, потом шаг.
        if (at && this.dead.has(at)) {
            const pass = () => { this.skip = true; history.back(); };
            if (sheetInHistory()) { setTimeout(pass); return; }
            e.stopImmediatePropagation();
            pass();
        }
    }

    // ——— высота на телефоне

    get vh() { return window.visualViewport?.height ?? innerHeight; }

    // Выше шапки лист не уходит: её кнопки нужны и при открытом документе.
    maxHeight() {
        const header = document.querySelector('.header')?.getBoundingClientRect().bottom ?? 0;
        return Math.round(this.vh - Math.max(header, 0) - 8);
    }

    snaps() {
        const max = this.maxHeight();
        return SNAPS.map((f) => Math.min(Math.round(this.vh * f), max));
    }

    startHeight() {
        const saved = Number(store.get('docs:h'));
        const snaps = this.snaps();
        const h = saved > 0 && saved < 1 ? saved * this.vh : this.vh * SNAPS[1];
        return Math.min(Math.max(h, snaps[0]), snaps[2]);
    }

    setHeight(h) {
        this.height = Math.max(0, Math.round(h));
        html.style.setProperty('--docs-h', `${this.height}px`);
    }

    settle(h) {
        const el = this.element;
        if (reduce.matches) { this.setHeight(h); return; }
        el.classList.add('is-settling');
        this.setHeight(h);
        clearTimeout(this.settling);
        this.settling = setTimeout(() => el.classList.remove('is-settling'), 350);
    }

    // Экран повернули, клавиатура поменяла высоту — лист не выше доступного.
    fit() {
        if (!this.shown) return;
        if (wide.matches) { this.setWidth(this.wanted()); return; }
        const max = this.maxHeight();
        if (this.height > max) this.setHeight(max);
    }

    dragStart(e) {
        if (wide.matches || this.drag || (e.pointerType === 'mouse' && (e.button !== 0 || e.target.closest('button, a')))) return;
        this.drag = { y: e.clientY, h: this.height, moved: false, id: e.pointerId, pts: [[e.timeStamp, e.clientY]] };
        addEventListener('pointermove', this.onMove);
        addEventListener('pointerup', this.onUp);
        addEventListener('pointercancel', this.onUp);
    }

    dragMove(e) {
        const d = this.drag;
        if (!d || e.pointerId !== d.id) return;
        const dy = e.clientY - d.y;
        if (!d.moved) {
            if (Math.abs(dy) < 8) return;
            d.moved = true;
            this.element.classList.add('is-dragging');
        }
        d.pts.push([e.timeStamp, e.clientY]);
        if (d.pts.length > 6) d.pts.shift();
        const max = this.maxHeight();
        const h = d.h - dy;
        this.setHeight(h > max ? max + Math.pow(h - max, .6) : h);
    }

    dragEnd(e) {
        const d = this.drag;
        if (!d || e.pointerId !== d.id) return;
        this.drag = null;
        removeEventListener('pointermove', this.onMove);
        removeEventListener('pointerup', this.onUp);
        removeEventListener('pointercancel', this.onUp);
        if (!d.moved) return;
        this.element.classList.remove('is-dragging');
        // Отпустили после перетаскивания — нажатие по вкладке под пальцем не считается.
        const swallow = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
        this.element.addEventListener('click', swallow, { capture: true, once: true });
        setTimeout(() => this.element.removeEventListener('click', swallow, { capture: true }), 50);
        const [t0, y0] = d.pts[0], [t1, y1] = d.pts.at(-1);
        const v = (y1 - y0) / Math.max(1, t1 - t0);
        const snaps = this.snaps();
        // Взмах добавляет не больше 150 px; с клавиатурой лист низкий и порог закрытия крошечный — жест вниз только
        // убирает клавиатуру, лист остаётся (07.10.2026, окна «норовят закрыться»).
        const aim = this.height - Math.max(-150, Math.min(150, v * 180));
        if (this.kb) { document.activeElement?.blur(); this.settle(Math.max(this.height, snaps[0])); return; }
        if (aim < snaps[0] * .7) { this.close(); return; }
        const h = snaps.reduce((a, b) => (Math.abs(b - aim) < Math.abs(a - aim) ? b : a));
        this.settle(h);
        if (!this.kb) store.set('docs:h', (h / this.vh).toFixed(3));
    }

    // Клавиатура: лист ужимается, чтобы над ним было видно поле; ушла — прежняя высота.
    keyboard() {
        const open = html.classList.contains('kb-open');
        if (open === this.kb) return;
        this.kb = open;
        if (!this.shown || this.host || wide.matches) return;
        if (open) {
            this.beforeKb = this.height;
            const cap = Math.round(this.vh * .42);
            if (this.height > cap) this.settle(cap);
        } else if (this.beforeKb) {
            this.settle(Math.min(this.beforeKb, this.maxHeight()));
            this.beforeKb = 0;
        }
        const field = document.activeElement;
        if (open && field?.matches('input, textarea, select')) setTimeout(() => this.keepVisible(field), 360);
    }

    focused(e) {
        const field = e.target;
        if (!this.shown || this.host || wide.matches || !field.matches?.('input, textarea, select') || this.element.contains(field)) return;
        // Лист во весь экран, а человек взялся за поле — опускаем до середины, иначе поле некуда поднять.
        const mid = this.snaps()[1];
        if (this.height > mid && !this.kb) this.settle(mid);
        clearTimeout(this.focusing);
        this.focusing = setTimeout(() => this.keepVisible(field), 380);
    }

    // Поле с фокусом — над листом и плашкой действий, под шапкой.
    keepVisible(field) {
        if (!this.shown || !field.isConnected || document.activeElement !== field) return;
        const r = field.getBoundingClientRect();
        let bottom = this.element.getBoundingClientRect().top;
        const bar = document.querySelector('.action-bar');
        if (bar && bar.offsetParent !== null) bottom = Math.min(bottom, bar.getBoundingClientRect().top);
        const top = Math.max(document.querySelector('.header')?.getBoundingClientRect().bottom ?? 0, 0);
        if (r.bottom > bottom - 12) scrollBy(0, r.bottom - bottom + 24);
        else if (r.top < top + 8) scrollBy(0, r.top - top - 24);
    }

    // ——— ширина на компьютере

    // Ширина, которую выбрал человек (или 44 % экрана); сама панель — в пределах 24rem…60 % экрана сейчас.
    wanted() {
        return Number(store.get('docs:w')) || innerWidth * .44;
    }

    setWidth(w) {
        const max = Math.round(innerWidth * .6);
        this.width = Math.round(Math.min(max, Math.max(384, w || 0)));
        html.style.setProperty('--docs-w', `${this.width}px`);
    }

    resizeStart(e) {
        if (!wide.matches || e.button !== 0) return;
        e.preventDefault();
        const move = (ev) => this.setWidth(innerWidth - ev.clientX);
        const up = () => {
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            this.element.classList.remove('is-resizing');
            store.set('docs:w', String(this.width));
        };
        this.element.classList.add('is-resizing');
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
    }
}
