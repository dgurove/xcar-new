import { Controller } from '@hotwired/stimulus';

// Окно «Что видит менеджер» (`x-deal.manager-view`, 25.11.2026): «Показать» / «Скрыть» у пунктов копятся, кнопка подвала — когда есть
// что сохранить, и говорит что: «Показать: паспорт, СТС», «Скрыть: счёт поставщика», «Отправить сообщение». Из письма кнопка есть всегда.
export default class extends Controller {
    static targets = ['submit', 'foot', 'text'];

    connect() {
        this.update();
    }

    update() {
        const picked = (name) => [...this.element.querySelectorAll(`input[name="${name}"]:checked`)].map((i) => i.dataset.label).filter(Boolean);
        // Подпись кнопки влезает в строку: без повторов («Паспорт собственника» двумя страницами — один раз), длинно — числом.
        const say = (verb, list) => {
            const lower = (l) => l.map((x) => (/^\p{Lu}\p{Ll}/u.test(x) ? x.charAt(0).toLowerCase() + x.slice(1) : x)).join(', ');
            const uniq = [...new Set(list)];
            const text = lower(uniq);
            return text.length > 26 ? `${verb} менеджеру: ${list.length}` : `${verb}: ${text}`;
        };
        this.element.querySelectorAll('.mview-act input').forEach((i) => i.closest('.mview-row')?.classList.toggle('is-pending', i.checked));
        const show = [...picked('show[]'), ...picked('line[]')];
        const hide = picked('hide[]');
        const text = (this.hasTextTarget ? this.textTarget.querySelector('textarea, input')?.value ?? this.textTarget.value ?? '' : '').trim();
        const parts = [];
        if (show.length) parts.push(say('Показать', show));
        if (hide.length) parts.push(say(show.length ? 'скрыть' : 'Скрыть', hide));
        const idle = this.hasSubmitTarget ? this.submitTarget.dataset.idle : '';
        const label = parts.join('; ') || (text ? 'Отправить сообщение' : idle);
        if (this.hasSubmitTarget) this.submitTarget.textContent = label;
        // Общий вид — кнопка только с изменениями; из письма (кнопка видна сразу) — всегда.
        if (this.hasFootTarget && idle === 'Сохранить') this.footTarget.hidden = !parts.length && !text;
    }
}
