import { Controller } from '@hotwired/stimulus';

// Строка прайса: ступень по суткам есть только у хранения и негабарита,
// километры в фиксе — только у эвакуации; лишние поля прячутся вместе с подписью.
export default class extends Controller {
    static targets = ['service', 'tier', 'km'];

    connect() {
        this.sync();
    }

    sync() {
        const s = this.serviceTarget.value;
        this.show(this.tierTarget, s === 'storage' || s === 'oversize');
        this.show(this.kmTarget, s === 'tow');
    }

    show(input, on) {
        input.closest('.field').hidden = !on;
    }
}
