import { Controller } from '@hotwired/stimulus';

// Закладка щёлкает под палец: заливка и прыжок в момент отправки, откат при ошибке;
// стрим сервера потом заменяет форму окончательным состоянием.
export default class extends Controller {
    press() {
        const button = this.element.querySelector('button');
        const on = button.classList.contains('is-on') || button.classList.contains('bg-accent-soft');
        this.was = on;
        this.paint(button, !on);
        button.classList.add('is-pop');
    }

    settle(event) {
        if (event.detail.success) return;
        this.paint(this.element.querySelector('button'), this.was);
        window.toast?.('Не получилось', 'danger');
    }

    paint(button, on) {
        const icon = button.querySelector('svg');
        if (button.classList.contains('card-star')) {
            button.classList.toggle('is-on', on);
            icon?.classList.toggle('fill-current', on);
        } else {
            button.classList.toggle('bg-accent-soft', on); button.classList.toggle('text-accent-text', on);
            button.classList.toggle('text-ink-muted', !on);
            icon?.classList.toggle('fill-accent', on); icon?.classList.toggle('text-accent', on); icon?.classList.toggle('text-ink-dim', !on);
        }
    }
}
