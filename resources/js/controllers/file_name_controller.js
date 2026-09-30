import { Controller } from '@hotwired/stimulus';

// Поле файла x-ui.file-field: после выбора вместо «Приложить» — имя файла (или «N файлов»).
export default class extends Controller {
    static targets = ['label'];

    show({ target }) {
        const files = [...(target.files || [])];
        this.labelTarget.textContent = files.length > 1 ? `${files.length} файла` : (files[0]?.name || 'Приложить');
        this.labelTarget.classList.toggle('text-ink-muted', files.length === 0);
    }
}
