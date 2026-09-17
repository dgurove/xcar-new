import { Controller } from '@hotwired/stimulus';

// Чат по офферу. Лента дополняется фрагментами «всё после N», где N —
// последний номер на экране: догон после обрыва идемпотентен; старое
// подгружается «до N» сверху. По каналу приходят только события
// {chat, seq}, текст всегда берётся с сервера. Своё меню у пузыря
// (долгое нажатие, правая кнопка, свайп вправо): ответить, скопировать,
// изменить, удалить; чип над полем держит ответ или правку. Фото — превью
// до отправки, вставка из буфера и drop; просмотр — Viewer.js как в галерее.
let viewerModule = null;
const loadViewer = () => (viewerModule ??= Promise.all([import('viewerjs'), import('viewerjs/dist/viewer.css')]).then(([m]) => m.default));

export default class extends Controller {
    static targets = ['list', 'form', 'input', 'files', 'submit', 'state', 'stateIcon', 'stateName', 'stateText', 'previews', 'menu', 'ownItem', 'copyItem', 'editItem', 'more'];
    static values = { url: String, open: String, id: Number, last: Number, readonly: Boolean };

    connect() {
        this.picked = [];
        this.mode = null; // {kind: 'reply'|'edit', seq}
        this.onVisible = () => document.visibilityState === 'visible' && this.fetch();
        this.onOpen = () => setTimeout(() => this.fetch(), 50);
        this.onOnline = () => this.retryFailed();
        document.addEventListener('visibilitychange', this.onVisible);
        window.addEventListener('chat:open', this.onOpen);
        window.addEventListener('online', this.onOnline);
        // Страховка без живого канала: раз в 20 секунд, пока лента на экране.
        this.timer = setInterval(() => this.fetch(), 20000);
        this.watchTop();
        this.scroll(this.listTarget.querySelector('#chat-new'));
        this.status = document.querySelector('[data-chat-status]');
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisible);
        window.removeEventListener('chat:open', this.onOpen);
        window.removeEventListener('online', this.onOnline);
        clearInterval(this.timer);
        clearTimeout(this.typingTimer);
        this.topWatcher?.disconnect();
        this.viewer?.destroy();
        this.viewer = null;
        this.picked.forEach((p) => URL.revokeObjectURL(p.url));
    }

    // ------------------------------------------------------------ live

    live(event) {
        const d = event.detail;
        if (d?.chat !== this.idValue) return;
        // Лента этого чата на экране — тост не нужен (live.js смотрит на флаг).
        if (this.visible()) d.handled = true;
        if (d.seq > this.lastValue) this.fetch();
    }

    edited(event) {
        const d = event.detail;
        if (d?.chat !== this.idValue || !this.urlValue) return;
        this.refetch(d.seq);
    }

    read(event) {
        const d = event.detail;
        if (d?.chat !== this.idValue) return;
        this.listTarget.querySelectorAll('.msg.is-mine:not(.is-read)').forEach((el) => Number(el.dataset.seq) <= d.seq && el.classList.add('is-read'));
    }

    typing(event) {
        if (event.detail?.chat !== this.idValue || !this.status) return;
        this.status.dataset.seen ??= this.status.textContent;
        this.status.innerHTML = 'печатает<span class="typing-dots"><i></i><i></i><i></i></span>';
        clearTimeout(this.typingTimer);
        this.typingTimer = setTimeout(() => { this.status.textContent = this.status.dataset.seen; }, 4000);
    }

    // Лента видна — значит прочитано; в закрытой шторке догоняем молча, бейдж остаётся.
    visible() {
        return document.visibilityState === 'visible' && (this.element.checkVisibility?.() ?? true);
    }

    headers() {
        return { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' };
    }

    async fetch() {
        if (!this.urlValue || this.fetching) return;
        this.fetching = true;
        try {
            const r = await fetch(`${this.urlValue}?after=${this.lastValue}&read=${this.visible() ? 1 : 0}`, { headers: this.headers() });
            if (r.ok) this.append(await r.text());
        } catch {} finally { this.fetching = false; }
    }

    async refetch(seq) {
        const r = await fetch(`${this.urlValue}/${seq}`, { headers: this.headers() });
        if (!r.ok) return;
        const box = document.createElement('div');
        box.innerHTML = await r.text();
        const fresh = box.querySelector('.msg');
        const old = this.listTarget.querySelector(`.msg[data-seq="${seq}"]`);
        if (!fresh || !old) return;
        // Склейка и день — от соседей, а не от одиночного ответа.
        fresh.classList.toggle('is-cont', old.classList.contains('is-cont'));
        if (fresh.classList.contains('is-cont')) fresh.querySelector('.msg-author')?.remove();
        old.replaceWith(fresh);
    }

    append(html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        // Первое сообщение завело чат: приветствие-заглушка уходит, лента дальше живёт по seq.
        if (!this.lastValue) this.listTarget.replaceChildren();
        const atBottom = this.atBottom();
        let mine = false;
        for (const el of [...box.children]) {
            if (el.classList.contains('chat-day')) {
                if (this.lastDay() !== el.dataset.day) this.listTarget.append(el);
                continue;
            }
            const seq = Number(el.dataset.seq);
            if (!seq || seq <= this.lastValue) continue;
            this.listTarget.querySelector('[data-pending]')?.remove();
            this.glue(el);
            this.listTarget.append(el);
            this.lastValue = seq;
            if (el.classList.contains('is-mine')) mine = true;
        }
        // Вниз — если и так были внизу или это своё; иначе читающего не дёргать, показать «↓».
        if (atBottom || mine) this.scroll();
        else this.unseen(1);
    }

    // Подряд идущее того же автора в пять минут — без имени, ближе к предыдущему.
    glue(el) {
        const prev = this.lastMessage();
        if (!prev || prev.dataset.author !== el.dataset.author || prev.dataset.day !== el.dataset.day || el.classList.contains('is-system') || prev.classList.contains('is-system')) return;
        const t = (m) => m.querySelector('.msg-meta .nums')?.textContent || '';
        const [ph, pm] = t(prev).split(':').map(Number), [h, m] = t(el).split(':').map(Number);
        if (Math.abs(h * 60 + m - (ph * 60 + pm)) < 5) { el.classList.add('is-cont'); el.querySelector('.msg-author')?.remove(); }
    }

    lastMessage() {
        const all = this.listTarget.querySelectorAll('.msg:not([data-pending])');
        return all[all.length - 1] || null;
    }

    lastDay() {
        const days = this.listTarget.querySelectorAll('[data-day]');
        return days[days.length - 1]?.dataset.day || '';
    }

    // ------------------------------------------------------------ старое сверху

    watchTop() {
        this.topWatcher?.disconnect();
        if (!this.hasMoreTarget) return;
        this.topWatcher = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && this.older(), { root: this.listTarget, rootMargin: '200px 0px 0px' });
        this.topWatcher.observe(this.moreTarget);
    }

    async older() {
        if (!this.hasMoreTarget || this.loadingOlder) return;
        this.loadingOlder = true;
        const sentinel = this.moreTarget, before = Number(sentinel.dataset.seq);
        try {
            const r = await fetch(`${this.urlValue}?before=${before}`, { headers: this.headers() });
            if (!r.ok) return;
            const box = document.createElement('div');
            box.innerHTML = await r.text();
            const list = this.listTarget, keep = list.scrollHeight - list.scrollTop;
            const first = sentinel.nextElementSibling;
            // Разделитель дня у бывшего первого сообщения — лишний, если старые того же дня.
            const days = [...box.querySelectorAll('.chat-day')];
            if (first?.classList.contains('chat-day') && days.length && days[days.length - 1].dataset.day === first.dataset.day) first.remove();
            sentinel.replaceWith(...box.children);
            list.scrollTop = list.scrollHeight - keep;
            this.watchTop();
        } finally { this.loadingOlder = false; }
    }

    // ------------------------------------------------------------ прокрутка

    atBottom() {
        const l = this.listTarget;
        return l.scrollHeight - l.scrollTop - l.clientHeight < 80;
    }

    scroll(to = null) {
        if (to) to.scrollIntoView({ block: 'start' });
        else this.listTarget.scrollTop = this.listTarget.scrollHeight;
        this.unseen(0);
    }

    scrolled() {
        if (this.atBottom()) this.unseen(0);
    }

    unseen(add) {
        let pill = this.element.querySelector('.chat-down');
        if (!add) { pill?.remove(); return; }
        if (!pill) {
            pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'chat-down';
            pill.dataset.count = '0';
            pill.addEventListener('click', () => this.scroll());
            this.listTarget.after(pill);
        }
        pill.dataset.count = String(Number(pill.dataset.count) + add);
        pill.textContent = `↓ ${pill.dataset.count}`;
    }

    // К сообщению, на которое отвечали: подсветка на секунду.
    jump(event) {
        const el = this.listTarget.querySelector(`.msg[data-seq="${event.currentTarget.dataset.seq}"]`);
        if (!el) return;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el.classList.add('is-flash');
        setTimeout(() => el.classList.remove('is-flash'), 1200);
    }

    // ------------------------------------------------------------ меню пузыря

    // Долгое нажатие и свайп — только пальцем: мышь открывает меню правой кнопкой (contextmenu),
    // а выделение текста и уход курсора с пузыря ничего не показывают.
    press(event) {
        if (event.pointerType === 'mouse') return;
        const el = event.currentTarget;
        this.pressed = { el, x: event.clientX, y: event.clientY, moved: false };
        clearTimeout(this.pressTimer);
        this.pressTimer = setTimeout(() => { if (this.pressed?.el === el && !this.pressed.moved) this.open(el, this.pressed.x, this.pressed.y); }, 500);
    }

    drag(event) {
        const p = this.pressed;
        if (!p || p.el !== event.currentTarget) return;
        const dx = event.clientX - p.x, dy = event.clientY - p.y;
        if (Math.abs(dy) > 12) { p.moved = true; this.slide(p.el, 0); return; }
        if (dx > 8) { p.moved = true; p.swipe = dx; this.slide(p.el, Math.min(dx, 72)); }
    }

    release(event) {
        clearTimeout(this.pressTimer);
        const p = this.pressed;
        if (!p) return;
        this.pressed = null;
        this.slide(p.el, 0);
        if (p.swipe > 40 && event.type === 'pointerup') this.setMode('reply', p.el);
    }

    slide(el, px) {
        el.style.translate = px ? `${px}px 0` : '';
    }

    menu(event) {
        this.open(event.currentTarget, event.clientX, event.clientY);
    }

    open(el, x, y) {
        if (this.readonlyValue || !this.hasMenuTarget) return;
        // Долгое нажатие и contextmenu на Android приходят вместе: второй раз меню не открывается заново.
        if (this.active === el && this.menuTarget.matches(':popover-open')) return;
        clearTimeout(this.pressTimer);
        this.pressed = null;
        this.active = el;
        const own = el.hasAttribute('data-own');
        const text = el.querySelector('.msg-bubble')?.dataset.text;
        this.ownItemTargets.forEach((b) => { b.hidden = !own; });
        // Изменить — только текст: у сообщения из одних фото править нечего.
        this.editItemTarget.hidden = !own || !text;
        this.copyItemTarget.hidden = !text;
        const menu = this.menuTarget;
        this.closeMenu();
        menu.showPopover();
        const w = document.documentElement.clientWidth, h = innerHeight;
        menu.style.left = `${Math.max(8, Math.min(x, w - menu.offsetWidth - 8))}px`;
        menu.style.top = `${Math.max(8, Math.min(y, h - menu.offsetHeight - 8))}px`;
        navigator.vibrate?.(10);
    }

    closeMenu() {
        if (this.hasMenuTarget && this.menuTarget.matches(':popover-open')) this.menuTarget.hidePopover();
    }

    reply() {
        this.closeMenu();
        this.setMode('reply', this.active);
    }

    async copy() {
        this.closeMenu();
        try {
            await navigator.clipboard.writeText(this.active?.querySelector('.msg-bubble')?.dataset.text || '');
            window.toast?.('Текст в буфере');
        } catch { window.toast?.('Не получилось скопировать', 'danger'); }
    }

    startEdit() {
        this.closeMenu();
        this.setMode('edit', this.active);
    }

    // Удаление — с подтверждением в самом пункте: первый тап переименовывает его, второй удаляет.
    async remove(event) {
        const item = event.currentTarget, label = item.lastChild;
        if (!item.dataset.sure) {
            item.dataset.sure = '1';
            label.textContent = 'Точно удалить';
            clearTimeout(item.sureTimer);
            item.sureTimer = setTimeout(() => { delete item.dataset.sure; label.textContent = 'Удалить'; }, 3000);
            return;
        }
        clearTimeout(item.sureTimer);
        delete item.dataset.sure;
        label.textContent = 'Удалить';
        this.closeMenu();
        const seq = this.active?.dataset.seq;
        if (!seq) return;
        const r = await fetch(`${this.urlValue}/${seq}`, { method: 'DELETE', headers: this.headers() });
        if (r.ok) this.swap(seq, await r.text());
        else window.toast?.('Не удалилось', 'danger');
    }

    swap(seq, html) {
        const box = document.createElement('div');
        box.innerHTML = html;
        const fresh = box.querySelector('.msg'), old = this.listTarget.querySelector(`.msg[data-seq="${seq}"]`);
        if (!fresh || !old) return;
        fresh.classList.toggle('is-cont', old.classList.contains('is-cont'));
        if (fresh.classList.contains('is-cont')) fresh.querySelector('.msg-author')?.remove();
        old.replaceWith(fresh);
    }

    // ------------------------------------------------------------ чип над полем

    setMode(kind, el) {
        if (!el || !this.hasStateTarget) return;
        const bubble = el.querySelector('.msg-bubble');
        const head = this.element.closest('.chat-page')?.querySelector('.chat-head .font-medium')?.textContent || '';
        const name = el.querySelector('.msg-author')?.textContent || (el.classList.contains('is-mine') ? 'Вы' : head);
        this.mode = { kind, seq: Number(el.dataset.seq), text: bubble?.dataset.text || '' };
        this.stateTarget.hidden = false;
        this.stateIconTarget.innerHTML = kind === 'edit'
            ? '<path d="M4 20h4l10.5-10.5a2 2 0 0 0-4-4L4 16v4Zm9.5-13.5 4 4"/>'
            : '<path d="m9 17-5-5 5-5m-5 5h11a4 4 0 0 1 4 4v2"/>';
        this.stateNameTarget.textContent = kind === 'edit' ? 'Изменение' : name;
        this.stateTextTarget.textContent = this.mode.text || (el.querySelector('.msg-photos') ? 'Фото' : el.querySelector('.msg-file')?.textContent || '');
        if (kind === 'edit') { this.inputTarget.value = this.mode.text; this.grow(); }
        this.inputTarget.focus();
    }

    cancel() {
        if (this.mode?.kind === 'edit') { this.inputTarget.value = ''; this.grow(); }
        this.mode = null;
        if (this.hasStateTarget) this.stateTarget.hidden = true;
    }

    keydown(event) {
        if (event.key === 'Escape' && this.mode) { event.preventDefault(); this.cancel(); return; }
        if (event.key === 'Enter' && !event.shiftKey && !('ontouchstart' in window)) {
            event.preventDefault();
            this.formTarget.requestSubmit();
        }
    }

    // Поле растёт до пяти строк за текстом.
    grow() {
        const i = this.inputTarget;
        i.style.height = 'auto';
        i.style.height = `${Math.min(i.scrollHeight, 140)}px`;
        // «Отправить» бледная, пока слать нечего.
        if (this.hasSubmitTarget) this.submitTarget.classList.toggle('is-empty', !i.value.trim() && this.picked.length === 0);
    }

    // «Печатает…» — другой стороне, не чаще раза в три секунды.
    typed() {
        this.grow();
        if (!this.urlValue || !this.inputTarget.value || this.typingSent > Date.now() - 3000) return;
        this.typingSent = Date.now();
        fetch(`${this.urlValue.replace(/\/messages$/, '')}/typing`, { method: 'POST', headers: this.headers() }).catch(() => {});
    }

    // ------------------------------------------------------------ файлы

    pick() {
        this.filesTarget.click();
    }

    filesPicked() {
        this.add([...this.filesTarget.files]);
        this.filesTarget.value = '';
    }

    paste(event) {
        const files = [...(event.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/') || f.type === 'application/pdf');
        if (files.length) { event.preventDefault(); this.add(files); }
    }

    over(event) {
        event.dataTransfer.dropEffect = 'copy';
    }

    drop(event) {
        if (this.readonlyValue) return;
        this.add([...(event.dataTransfer?.files || [])]);
    }

    add(files) {
        for (const f of files) {
            if (this.picked.length >= 10) break;
            this.picked.push({ file: f, url: f.type.startsWith('image/') ? URL.createObjectURL(f) : null });
        }
        this.previews();
    }

    dropFile(event) {
        const i = Number(event.currentTarget.dataset.index);
        const [gone] = this.picked.splice(i, 1);
        if (gone?.url) URL.revokeObjectURL(gone.url);
        this.previews();
    }

    previews() {
        if (!this.hasPreviewsTarget) return;
        const box = this.previewsTarget;
        box.replaceChildren();
        box.hidden = this.picked.length === 0;
        this.grow();
        this.picked.forEach((p, i) => {
            const item = document.createElement('div');
            item.className = 'chat-preview';
            if (p.url) { const img = new Image(); img.src = p.url; img.alt = ''; item.append(img); }
            else { item.classList.add('is-file'); item.textContent = p.file.name; }
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'chat-preview-x';
            x.setAttribute('aria-label', 'Убрать');
            x.dataset.index = String(i);
            x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
            x.addEventListener('click', (e) => this.dropFile(e));
            item.append(x);
            box.append(item);
        });
    }

    // Просмотр фото ленты во весь экран — Viewer.js по всем фото чата, начиная с нажатого.
    async view(event) {
        const images = [...this.listTarget.querySelectorAll('.msg-photos img')];
        const index = images.indexOf(event.currentTarget.querySelector('img'));
        const Viewer = await loadViewer();
        this.viewer?.destroy();
        this.viewer = new Viewer(this.listTarget, {
            filter: (img) => img.closest('.msg-photos') !== null,
            navbar: images.length > 1, title: false, toolbar: false, transition: false, tooltip: false, movable: true, zoomRatio: .3,
            initialViewIndex: Math.max(0, index),
            hidden: () => { this.viewer?.destroy(); this.viewer = null; document.body.classList.remove('viewer-open'); },
            shown: () => document.body.classList.add('viewer-open'),
        });
        this.viewer.show();
    }

    // ------------------------------------------------------------ отправка

    // Пузырь появляется в момент отправки; ответ сервера его заменяет, ошибка красит — тап повторяет.
    pending(text, files) {
        const el = document.createElement('div');
        el.className = 'msg is-mine is-pending';
        el.dataset.pending = '1';
        el.innerHTML = '<div class="msg-bubble"><div class="msg-text"></div><div class="msg-meta"><span class="nums"></span></div></div>';
        el.querySelector('.msg-text').textContent = text || (files.length ? `Фото: ${files.length}` : '');
        el.querySelector('.msg-meta span').textContent = new Date().toLocaleTimeString('ru', { hour: '2-digit', minute: '2-digit' });
        this.listTarget.append(el);
        this.scroll();
        return el;
    }

    async send(event) {
        event?.preventDefault();
        const text = this.inputTarget.value.trim();
        const files = this.picked.map((p) => p.file);
        if (!text && !files.length) return;
        const mode = this.mode;
        this.inputTarget.value = '';
        this.grow();
        this.formTarget.dispatchEvent(new CustomEvent('draft:clear'));
        this.picked.forEach((p) => p.url && URL.revokeObjectURL(p.url));
        this.picked = [];
        this.previews();
        this.cancel();
        this.inputTarget.focus();
        if (mode?.kind !== 'edit') { await this.deliver(text, files, mode?.seq); return; }
        // Правка меняет текст; приложенные при этом фото уходят следом отдельным сообщением.
        if (text) await this.saveEdit(mode.seq, text);
        if (files.length) await this.deliver('', files);
    }

    async saveEdit(seq, text) {
        const form = new FormData();
        form.append('text', text);
        form.append('_method', 'PATCH');
        const r = await fetch(`${this.urlValue}/${seq}`, { method: 'POST', body: form, headers: this.headers() });
        if (r.ok) this.swap(seq, await r.text());
        else window.toast?.((await r.json().catch(() => ({}))).message || 'Не сохранилось', 'danger');
    }

    async deliver(text, files, replyTo = null) {
        this.listTarget.querySelector('[data-pending]')?.remove();
        const bubble = this.pending(text, files);
        const form = new FormData();
        form.append('text', text);
        form.append('after', this.lastValue);
        if (replyTo) form.append('reply_to', replyTo);
        files.forEach((f) => form.append('files[]', f));
        try {
            // Чата ещё нет — первое сообщение уходит на open, ответ приносит адрес ленты.
            const r = await fetch(this.urlValue || this.openValue, { method: 'POST', body: form, headers: this.headers() });
            if (!r.ok) {
                const d = await r.json().catch(() => ({}));
                throw new Error(d.message || (r.status === 401 || r.status === 419 ? 'Войдите заново' : 'Не отправилось'));
            }
            if (!this.urlValue) {
                this.urlValue = r.headers.get('X-Chat-Url') || '';
                this.idValue = Number(r.headers.get('X-Chat-Id') || 0);
                // Экран «написать» по ТС стал чатом — адрес теперь его, чтобы обновление и «назад» вели сюда же;
                // на широком экране страница перечитывается: слева должен появиться и сам чат.
                if (this.idValue && this.element.closest('.chat-page') && location.pathname.startsWith('/account/chats/')) {
                    if (matchMedia('(min-width: 1024px)').matches) { window.Turbo.visit(`/account/chats/${this.idValue}`, { action: 'replace' }); return; }
                    window.Turbo.session.history.replace(new URL(`/account/chats/${this.idValue}`, location.href));
                }
            }
            this.append(await r.text());
            bubble.remove();
        } catch (e) {
            bubble.classList.add('is-failed');
            bubble.title = e.message;
            bubble.retry = () => { bubble.remove(); this.deliver(text, files, replyTo); };
            bubble.addEventListener('click', bubble.retry, { once: true });
        }
    }

    // Сеть вернулась — неотправленное уходит само.
    retryFailed() {
        this.listTarget.querySelectorAll('[data-pending].is-failed').forEach((b) => b.retry?.());
    }
}
