import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { closeSheet } from '../sheet';

// Telegram у аккаунта. Ссылка на бота открывает Telegram, страница остаётся и ждёт:
// ответ приходит событием хаба (live:telegram) или когда человек вернулся на вкладку.
//   link  — окошко и строка профиля: привязали — тост и страница перечитывается без окошка;
//   login — страница входа: «Войти» в чате — спрашиваем сервер и входим, «Это не я» — назад.
// shown — окошко показали: сервер не покажет его снова до завтра, даже если его просто смахнули.
export default class extends Controller {
    static targets = ['label'];
    static values = { mode: String, token: String, shown: Boolean };

    connect() {
        this.onLive = (e) => this.live(e.detail || {});
        this.onVisible = () => document.visibilityState === 'visible' && this.waiting && this.back();
        document.addEventListener('live:telegram', this.onLive);
        document.addEventListener('visibilitychange', this.onVisible);
        if (this.shownValue) this.post('/account/telegram/later', { shown: 1 });
    }

    disconnect() {
        document.removeEventListener('live:telegram', this.onLive);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    // Нажали «Привязать» / «Войти через Telegram»: ссылка уходит в Telegram сама, мы ждём.
    wait() {
        this.waiting = true;
        this.original ??= this.labelTarget.textContent;
        this.labelTarget.textContent = this.modeValue === 'login' ? 'Подтвердите в Telegram' : 'Нажмите «Запустить» в Telegram';
    }

    later() {
        this.post('/account/telegram/later', {});
        this.close();
    }

    live({ state }) {
        if (this.modeValue === 'link' && state === 'linked') {
            window.toast?.('Telegram привязан');
            // Сначала снять запись шторки в истории, потом перечитать страницу — уже без окошка.
            this.close().then(() => Turbo.visit(location.href, { action: 'replace' }));
        } else if (this.modeValue === 'login' && state === 'login') {
            this.check();
        } else if (this.modeValue === 'login' && state === 'denied') {
            this.reset('Вход отклонён');
        }
    }

    // Вернулись из Telegram, а событие хаба не дошло (или хаба нет): спросить самим.
    back() {
        if (this.modeValue === 'login') this.check();
        else Turbo.visit(location.href, { action: 'replace' });
    }

    async check() {
        const r = await this.post(`/login/telegram/${this.tokenValue}`, {});
        if (!r) return;
        if (r.status === 200) {
            location.href = (await r.json()).href;
        } else if (r.status === 410) {
            // «Это не я» или ссылка устарела — страница входа выдаст новую.
            Turbo.visit(location.href, { action: 'replace' });
        }
    }

    reset(message) {
        this.waiting = false;
        if (this.original) this.labelTarget.textContent = this.original;
        if (message) window.toast?.(message);
    }

    close() {
        const dialog = this.element.closest('dialog');
        return dialog ? closeSheet(dialog) : Promise.resolve();
    }

    async post(url, data) {
        const body = new FormData();
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        try {
            return await fetch(url, {
                method: 'POST', body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
        } catch {
            return null;
        }
    }
}
