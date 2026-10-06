import { Controller } from '@hotwired/stimulus';

// «Приём подтверждений до» — день и время раздельно: выбрали день, а времени ещё нет — сразу 17:30 (владелец 06.10.2026),
// его можно поменять. Одно поле datetime-local в Chrome без времени значения не отдаёт. В форму уходит скрытое `bids_close_at`.
export default class extends Controller {
    static targets = ['day', 'time', 'out'];

    day() {
        if (this.dayTarget.value && !this.timeTarget.value) this.timeTarget.value = '17:30';
        this.sync();
    }

    sync() {
        const day = this.dayTarget.value;
        this.outTarget.value = day ? `${day}T${this.timeTarget.value || '17:30'}` : '';
        this.outTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
