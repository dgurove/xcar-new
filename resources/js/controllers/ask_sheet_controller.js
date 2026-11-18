import { Controller } from '@hotwired/stimulus';
import { openSheet } from '../sheet';

// Вопрос при входе (10.11.2026, владелец: «Забрал» за менеджеров жали админы — 80 раз из 96): «Забрали автомобиль?» шторкой
// сама на «Предложениях» и «Сделках», если поверх ничего нет (Telegram и пуши ждут её — `dialog:modal`).
// С 18.11.2026 (владелец: «шторка нужна, подумай, как довести до ума»):
// — при входе выезжает через 1,2 с; ответил или «Ещё нет» — следующее дело встаёт сразу (форма вернула на тот же экран,
//   метка `asks:next` в sessionStorage), без нового входа;
// — закрыли без ответа (крестик, жест, мимо) — ничего не откладывается на сервере: шторка молчит три часа на этом
//   устройстве (`asks:quiet` в localStorage), дальше спросит снова; «до завтра» — только кнопкой «Ещё нет»;
// — машина в шторке — ссылка: посмотреть, о чём речь, дело не откладывается, шторка спросит при следующем входе;
// — возврат «назад» и снимок страницы шторку не открывают: вопрос — при входе, а не поверх того, куда вернулись.
const QUIET = 3 * 60 * 60 * 1000;
const NEXT = 15 * 1000;
let restoring = false;
document.addEventListener('turbo:visit', (e) => { restoring = e.detail.action === 'restore'; });

const read = (store, key) => { try { return Number(store.getItem(key)) || 0; } catch { return 0; } };
const write = (store, key, value) => { try { if (value) store.setItem(key, String(value)); else store.removeItem(key); } catch {} };

export default class extends Controller {
    connect() {
        this.dialog = this.element.querySelector('dialog');
        // Закрыли, не ответив и не уйдя по ссылке, — тишина на три часа, без запроса.
        this.onClose = () => { if (!this.leaving) write(localStorage, 'asks:quiet', Date.now() + QUIET); };
        this.dialog?.addEventListener('close', this.onClose);
        // Снимок не должен запомнить шторку открытой.
        this.onCache = () => { this.leaving = true; if (this.dialog?.open) this.dialog.close(); };
        document.addEventListener('turbo:before-cache', this.onCache);
        if (!this.dialog || restoring || document.documentElement.hasAttribute('data-turbo-preview')) return;
        const next = Date.now() - read(sessionStorage, 'asks:next') < NEXT;
        write(sessionStorage, 'asks:next', 0);
        if (!next && read(localStorage, 'asks:quiet') > Date.now()) return;
        this.timer = setTimeout(() => !document.querySelector('dialog:modal') && openSheet(this.dialog), next ? 250 : 1200);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.dialog?.removeEventListener('close', this.onClose);
        document.removeEventListener('turbo:before-cache', this.onCache);
    }

    // Ответ или «Ещё нет»: форма уйдёт и вернёт на этот же экран — следующее дело сразу.
    answered() {
        this.leaving = true;
        write(sessionStorage, 'asks:next', Date.now());
        write(localStorage, 'asks:quiet', 0);
    }

    // Ушли смотреть машину — это не «потом».
    look() {
        this.leaving = true;
    }
}
