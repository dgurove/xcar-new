import { Controller } from '@hotwired/stimulus';
import { copyText } from '../lib/clipboard';

// «Запросы» вендора (16.10.2026): галки «К запросу» — число на кнопке «Отправить 16 номеров» и плашка, пока отмечено
// хоть одно; нажатие кладёт отмеченные номера столбиком в буфер (в момент нажатия — потом браузер не даст) и
// отправляет форму: запрос, ссылки у предложений и номера в Telegram делает сервер.
const plural = (n) => {
    const a = n % 10, b = n % 100;
    return a === 1 && b !== 11 ? 'номер' : a >= 2 && a <= 4 && (b < 12 || b > 14) ? 'номера' : 'номеров';
};

export default class extends Controller {
    static targets = ['box', 'label', 'bar'];

    connect() { this.sync(); }

    get picked() { return this.boxTargets.filter((b) => b.checked); }

    sync() {
        const n = this.picked.length;
        this.labelTargets.forEach((l) => { l.textContent = `Отправить ${n} ${plural(n)}`; });
        this.barTargets.forEach((b) => { b.hidden = n === 0; });
    }

    copy() {
        const text = this.picked.map((b) => b.dataset.ref).join('\n');
        // Тоста об успехе нет: следом уходит форма, номера придут и в Telegram.
        copyText(text, null);
    }
}
