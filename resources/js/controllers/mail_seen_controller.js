import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Письмо справа во «Входящих» (17.11.2026) прочитано, когда его показали: открытое — сразу, другое письмо цепочки — когда его
// раскрыли (`MailController::seen`, у нас и в ящике). Не сервером при ответе: ответ панели берёт и предзагрузка соседних строк
// (`detail_controller`), она письмо прочитанным не делает. Строки, что стали прочитанными (письмо и прежние в его ветке), теряют
// жирный в списке и в цепочке; число «Входящих» становится меньше. Редактор ответа встал под письмом — прокрутить к нему саму
// панель, не страницу.
export default class extends Controller {
    static values = { url: String, base: String, unread: Boolean };

    connect() {
        this.onFrame = (e) => {
            if (!e.target.id?.startsWith('reply-pane') || !e.target.childElementCount) return;
            const box = this.element.closest('.split-detail');
            if (!box) return;
            const top = e.target.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop - 16;
            box.scrollTo({ top, behavior: 'smooth' });
        };
        // Раскрыли непрочитанное письмо цепочки — прочитано (toggle не всплывает — ловим на погружении).
        this.onToggle = (e) => {
            const node = e.target.matches?.('details.letter-body') && e.target.open ? e.target.closest('.letter[data-unread]') : null;
            if (node) this.read(`${this.baseValue}/messages/${node.dataset.message}/seen`);
        };
        this.element.addEventListener('turbo:frame-load', this.onFrame);
        this.element.addEventListener('toggle', this.onToggle, true);
        if (this.unreadValue) {
            this.unreadValue = false;
            this.read(this.urlValue);
        }
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrame);
        this.element.removeEventListener('toggle', this.onToggle, true);
    }

    async read(url) {
        const r = await request(url, { method: 'POST', json: { read: true }, quiet: true });
        const data = await r?.json().catch(() => null);
        if (!data?.ids) return;
        for (const id of data.ids) {
            document.getElementById(`inbox-${id}`)?.classList.remove('is-unread');
            const node = document.getElementById(`msg-${id}`);
            node?.classList.remove('letter--unread');
            node?.removeAttribute('data-unread');
        }
        document.querySelectorAll('[data-pill-count="in"]').forEach((el) => {
            const n = Math.max(0, (Number(el.textContent.replace(/\D/g, '')) || 0) - data.ids.length);
            el.textContent = n ? String(n) : '';
        });
    }
}
