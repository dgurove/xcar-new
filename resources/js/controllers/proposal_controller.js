import { Controller } from '@hotwired/stimulus';

// Плашка «Предложено из заявок на приём» (x-offer.proposal, 13.10.2026): сначала движение, потом запрос — иначе морф ответа
// снял бы плашку раньше, чем её видно. «Принять» — волна, искра становится галочкой, плашка схлопывается; «Отклонить» —
// плашка гаснет вниз, а строку из списка и переход окна к следующей делает ответ (detail-gone, flip.js).
const still = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export default class extends Controller {
    accept(event) {
        this.play(event, ['is-accepting'], 650, () => {
            this.element.style.setProperty('--h', `${this.element.offsetHeight}px`);
            this.element.classList.add('is-closing');
        }, 380);
    }

    decline(event) {
        this.play(event, ['is-declining'], 360);
    }

    play(event, classes, wait, then = null, after = 0) {
        const form = event.target;
        if (form.dataset.proposalGo || still()) return;
        event.preventDefault();
        this.element.classList.add('is-busy', ...classes);
        setTimeout(() => {
            then?.();
            setTimeout(() => {
                form.dataset.proposalGo = '1';
                // Не дошло (нет связи, ошибка) — плашка возвращается, нажать можно снова.
                form.addEventListener('turbo:submit-end', (e) => {
                    if (e.detail.success) return;
                    delete form.dataset.proposalGo;
                    this.element.classList.remove('is-busy', 'is-closing', ...classes);
                }, { once: true });
                form.requestSubmit();
            }, after);
        }, wait);
    }
}
