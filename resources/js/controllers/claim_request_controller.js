import { Controller } from '@hotwired/stimulus';
import { copyText } from '../lib/clipboard';
import { plural } from '../lib/plural.js';

// «Запросы» вендора (16.10.2026): галки «К запросу» — число на кнопке «Отправить 16 номеров» и плашка, пока отмечено
// хоть одно; нажатие кладёт отмеченные номера столбиком в буфер (в момент нажатия — потом браузер не даст) и
// отправляет форму: запрос, ссылки у предложений и номера в Telegram делает сервер.

export default class extends Controller {
    static targets = ['box', 'label', 'bar'];

    connect() { this.sync(); }

    get picked() { return this.boxTargets.filter((b) => b.checked); }

    sync() {
        const n = this.picked.length;
        this.labelTargets.forEach((l) => { l.textContent = `Отправить ${n} ${plural(n, ['номер', 'номера', 'номеров'])}`; });
        this.barTargets.forEach((b) => { b.hidden = n === 0; });
    }

    copy() {
        const text = this.picked.map((b) => b.dataset.ref).join('\n');
        // Тоста об успехе нет: следом уходит форма, номера придут и в Telegram.
        copyText(text, null);
    }
}
