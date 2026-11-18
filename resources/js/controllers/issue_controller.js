// «Выставить» комиссию (18.11.2026, владелец: «отправляется в чат менеджеру, отображается в сделке и копируется в буфер обмена»).
// Ссылка появляется только после ответа сервера, а писать в буфер можно только на самом нажатии: в буфер кладём обещание
// (ClipboardItem с Promise — Safari и Chrome), оно разрешится ответом. Не вышло — writeText после ответа. Потом — та же страница.
import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

export default class extends Controller {
    static values = { url: String };

    async issue(event) {
        event.preventDefault();
        if (this.element.disabled) return;
        this.element.disabled = true;
        const reply = request(this.urlValue, { method: 'POST' }).then((r) => (r ? r.json() : null));
        const copied = (async () => {
            try {
                const blob = reply.then((d) => new Blob([d?.copy ?? ''], { type: 'text/plain' }));
                await navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
                return true;
            } catch {
                const d = await reply;
                try { if (d?.copy) { await navigator.clipboard.writeText(d.copy); return true; } } catch { /* буфер недоступен */ }
                return false;
            }
        })();
        const data = await reply;
        if (!data) { this.element.disabled = false; return; }
        const ok = await copied;
        const say = ok ? data.toast : data.toast.replace(' и в буфере', '');
        document.addEventListener('turbo:load', () => window.toast?.(say), { once: true });
        window.Turbo?.visit(location.href, { action: 'replace' });
    }
}
