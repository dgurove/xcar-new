import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Письмо открыли справа во «Входящих» (17.11.2026) — оно прочитано: у нас и в ящике (`MailController::seen`). Зовётся при
// показе, а не сервером при ответе: ответ панели берёт и предзагрузка соседних строк (`detail_controller`), она письмо
// прочитанным не делает. Строки, что стали прочитанными (письмо и прежние в его ветке), теряют жирный; число «Входящих»
// становится меньше.
export default class extends Controller {
    static values = { url: String, unread: Boolean };

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrame);
    }

    async connect() {
        // Редактор ответа встал под письмом — прокрутить к нему саму панель, не страницу (список остаётся на месте).
        this.onFrame = (e) => {
            if (!e.target.id?.startsWith('reply-pane') || !e.target.childElementCount) return;
            const box = this.element.closest('.split-detail');
            if (!box) return;
            const top = e.target.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop - 16;
            box.scrollTo({ top, behavior: 'smooth' });
        };
        this.element.addEventListener('turbo:frame-load', this.onFrame);
        if (!this.unreadValue) return;
        this.unreadValue = false;
        const r = await request(this.urlValue, { method: 'POST', json: { read: true }, quiet: true });
        const data = await r?.json().catch(() => null);
        if (!data?.ids) return;
        for (const id of data.ids) document.getElementById(`inbox-${id}`)?.classList.remove('is-unread');
        document.querySelectorAll('[data-pill-count="in"]').forEach((el) => {
            const n = Math.max(0, (Number(el.textContent.replace(/\D/g, '')) || 0) - data.ids.length);
            el.textContent = n ? String(n) : '';
        });
    }
}
