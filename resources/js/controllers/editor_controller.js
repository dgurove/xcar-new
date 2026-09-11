import { Controller } from '@hotwired/stimulus';
import 'trix';

// Trix без вложений: файлы к письму идут отдельным списком.
export default class extends Controller {
    static targets = ['editor'];

    connect() {
        this.block = (e) => e.preventDefault();
        this.editorTarget.addEventListener('trix-file-accept', this.block);
    }

    disconnect() {
        this.editorTarget.removeEventListener('trix-file-accept', this.block);
    }
}
