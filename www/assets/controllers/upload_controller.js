import { Controller } from '@hotwired/stimulus';

/**
 * Upload Controller s Drag & Drop podporou
 *
 * Funkce:
 * - Drag & drop file upload
 * - Click na zónu otevře file picker
 * - Preview souborů před uploadem
 * - Možnost odstranit soubory před submitem
 * - Validace na frontendu (velikost, typ)
 */
export default class extends Controller {
    static targets = ['input', 'dropzone', 'preview', 'fileList'];
    static values = {
        maxSize: { type: Number, default: 10 * 1024 * 1024 }, // 10MB
        maxFiles: { type: Number, default: 10 },
        accept: { type: String, default: 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.odt,.ods,.odp' }
    };

    connect() {
        this.files = [];
        this.setupDropzone();
    }

    setupDropzone() {
        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            this.dropzoneTarget.addEventListener(eventName, this.preventDefaults.bind(this), false);
            document.body.addEventListener(eventName, this.preventDefaults.bind(this), false);
        });

        // Highlight drop zone when item is dragged over it
        ['dragenter', 'dragover'].forEach(eventName => {
            this.dropzoneTarget.addEventListener(eventName, this.highlight.bind(this), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.dropzoneTarget.addEventListener(eventName, this.unhighlight.bind(this), false);
        });

        // Handle dropped files
        this.dropzoneTarget.addEventListener('drop', this.handleDrop.bind(this), false);
    }

    preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    highlight() {
        this.dropzoneTarget.classList.add('border-blue-500', 'bg-blue-50');
    }

    unhighlight() {
        this.dropzoneTarget.classList.remove('border-blue-500', 'bg-blue-50');
    }

    handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        this.handleFiles(files);
    }

    // Click na dropzone otevře file picker
    openFilePicker() {
        this.inputTarget.click();
    }

    // Handle file input change (klasický výběr)
    handleInputChange(e) {
        const files = e.target.files;
        this.handleFiles(files);
    }

    handleFiles(fileList) {
        // Convert FileList to Array
        const filesArray = Array.from(fileList);

        // Validace
        const validFiles = filesArray.filter(file => this.validateFile(file));

        // Kontrola max počtu souborů
        const totalFiles = this.files.length + validFiles.length;
        if (totalFiles > this.maxFilesValue) {
            this.showError(`Maximální počet souborů je ${this.maxFilesValue}`);
            return;
        }

        // Přidat validní soubory
        validFiles.forEach(file => {
            this.files.push(file);
            this.addFilePreview(file);
        });

        // Aktualizovat file input
        this.updateFileInput();
    }

    validateFile(file) {
        // Kontrola velikosti
        if (file.size > this.maxSizeValue) {
            this.showError(`Soubor "${file.name}" je příliš velký. Maximální velikost je ${this.formatBytes(this.maxSizeValue)}.`);
            return false;
        }

        // Kontrola typu souboru
        const acceptedTypes = this.acceptValue.split(',').map(t => t.trim());
        const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
        const mimeType = file.type;

        const isAccepted = acceptedTypes.some(accept => {
            if (accept.endsWith('/*')) {
                // Wildcard match (např. "image/*")
                const prefix = accept.slice(0, -2);
                return mimeType.startsWith(prefix);
            } else if (accept.startsWith('.')) {
                // Extension match
                return fileExtension === accept.toLowerCase();
            } else {
                // Exact MIME type match
                return mimeType === accept;
            }
        });

        if (!isAccepted) {
            this.showError(`Soubor "${file.name}" má nepodporovaný formát.`);
            return false;
        }

        return true;
    }

    addFilePreview(file) {
        const fileId = Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        const isImage = file.type.startsWith('image/');

        const previewHtml = `
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200" data-file-id="${fileId}">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    ${isImage ? this.getImagePreview(file, fileId) : this.getFileIcon(file)}
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">${this.escapeHtml(file.name)}</p>
                        <p class="text-xs text-gray-500">${this.formatBytes(file.size)}</p>
                    </div>
                </div>
                <button type="button"
                        class="ml-2 p-1 text-red-600 hover:text-red-800 hover:bg-red-50 rounded transition"
                        data-action="click->upload#removeFile"
                        data-file-id="${fileId}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        `;

        this.previewTarget.insertAdjacentHTML('beforeend', previewHtml);

        // Pokud je obrázek, načti preview
        if (isImage) {
            this.loadImagePreview(file, fileId);
        }

        // Store file ID
        file.previewId = fileId;
    }

    getImagePreview(file, fileId) {
        return `
            <div class="w-12 h-12 bg-gray-200 rounded overflow-hidden flex-shrink-0">
                <img id="preview-${fileId}" src="" alt="" class="w-full h-full object-cover hidden">
                <div class="w-full h-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </div>
        `;
    }

    loadImagePreview(file, fileId) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = document.getElementById(`preview-${fileId}`);
            if (img) {
                img.src = e.target.result;
                img.classList.remove('hidden');
                img.parentElement.querySelector('div')?.remove();
            }
        };
        reader.readAsDataURL(file);
    }

    getFileIcon(file) {
        const extension = file.name.split('.').pop().toLowerCase();
        let iconColor = 'text-gray-400';
        let iconPath = 'M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z';

        if (file.type === 'application/pdf') {
            iconColor = 'text-red-500';
        } else if (extension === 'xls' || extension === 'xlsx' || file.type.includes('spreadsheet')) {
            iconColor = 'text-green-500';
        } else if (extension === 'doc' || extension === 'docx' || file.type.includes('word')) {
            iconColor = 'text-blue-600';
        }

        return `
            <div class="w-12 h-12 flex items-center justify-center flex-shrink-0">
                <svg class="w-8 h-8 ${iconColor}" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="${iconPath}"/>
                </svg>
            </div>
        `;
    }

    removeFile(e) {
        const fileId = e.currentTarget.dataset.fileId;

        // Odebrat z pole files
        this.files = this.files.filter(file => file.previewId !== fileId);

        // Odebrat preview element
        const previewElement = this.previewTarget.querySelector(`[data-file-id="${fileId}"]`);
        if (previewElement) {
            previewElement.remove();
        }

        // Aktualizovat file input
        this.updateFileInput();
    }

    updateFileInput() {
        // Vytvořit nový DataTransfer objekt
        const dataTransfer = new DataTransfer();

        // Přidat všechny soubory
        this.files.forEach(file => {
            dataTransfer.items.add(file);
        });

        // Nastavit files na input
        this.inputTarget.files = dataTransfer.files;

        // Update file count display
        this.updateFileCount();
    }

    updateFileCount() {
        if (this.hasFileListTarget) {
            const count = this.files.length;
            if (count > 0) {
                this.fileListTarget.textContent = `${count} ${count === 1 ? 'soubor' : count < 5 ? 'soubory' : 'souborů'} vybraných`;
                this.fileListTarget.classList.remove('hidden');
            } else {
                this.fileListTarget.classList.add('hidden');
            }
        }
    }

    showError(message) {
        // Zobrazit toast s chybou
        const event = new CustomEvent('toast:show', {
            detail: { message, type: 'error' },
            bubbles: true
        });
        this.element.dispatchEvent(event);
    }

    formatBytes(bytes, decimals = 1) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
