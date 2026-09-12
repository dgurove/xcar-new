import { Controller } from '@hotwired/stimulus';

// Trix без вложений: файлы к письму идут отдельным списком. Сам Trix — половина
// бандла — грузится здесь, только на экране с редактором.
export default class extends Controller {
    static targets = ['editor'];

    async connect() {
        await import('trix');
        this.block = (e) => e.preventDefault();
        this.editorTarget.addEventListener('trix-file-accept', this.block);
    }

    disconnect() {
        this.editorTarget.removeEventListener('trix-file-accept', this.block);
    }
}
