import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet, backdrop } from '../sheet';

// Окно «Вы уверены?» перед «Беру в работу» (`x-deal.take-sheet`, 19.11.2026, начальник): три кнопки — «Да, беру в работу»,
// «Ещё подумаю…» (экран выбора часов напоминания) и «Отказываюсь» (своё «Вы уверены?» с красной «Да, отказываюсь»).
// `confirm.js` умеет две кнопки и один экран, поэтому своя шторка: экраны — `pane`, открывает событие `take:<id>` от
// кнопки (`emit#send`). Ответ уходит формой шага, что стоит вне шторки (кнопка с атрибутом `form`): сначала шторка
// закрывается и снимает свою запись «Назад», потом форма отправляется — иначе возврат по истории сбивал бы переход Turbo.
// Напоминание — свои формы в шторке, их закрывает общее правило POST из шторки (`app.js`).
export default class extends Controller {
    static targets = ['pane'];

    open() {
        this.show('main');
        openSheet(this.element);
    }

    later() { this.show('later'); }

    refuse() { this.show('refuse'); }

    main() { this.show('main'); }

    close() { closeSheet(this.element); }

    backdrop(event) { backdrop(this.element, event); }

    send(event) {
        const button = event.currentTarget;
        const form = button.form;
        if (!form) return;
        event.preventDefault();
        closeSheet(this.element).then(() => form.requestSubmit(button));
    }

    show(name) {
        this.paneTargets.forEach((pane) => { pane.hidden = pane.dataset.pane !== name; });
    }
}
