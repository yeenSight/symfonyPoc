import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

const ACCEPTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const NEW_ALBUM = '__new__';

export default class extends Controller {
    static targets = ['dropzone', 'input', 'list', 'template', 'albumSource', 'albumAll', 'dateAll', 'toolbar', 'submit', 'summary', 'modal', 'albumName', 'albumDescription', 'albumError', 'albumSave', 'dispatch', 'dispatchName', 'dispatchSave', 'dispatchMessage', 'dispatchCredentials', 'dispatchResultModal', 'dispatchResultIdentifier', 'dispatchResultCode', 'dispatchResetRetry'];
    static values = { url: String, token: String, today: String, albumUrl: String, albumToken: String, dispatchUrl: String, dispatchToken: String, dispatchResetUrl: String, dispatchResetToken: String };

    connect() {
        this.items = [];
        this.albumModal = new Modal(this.modalTarget);
        this.dispatchCodes = new Map();
        this.dispatchCredentialElements = new Map();
        this.pendingDispatchReset = null;
        if (this.hasDispatchResultModalTarget) {
            this.dispatchResultModal = new Modal(this.dispatchResultModalTarget);
        }
        this.pendingSelect = null;
        this.modalTarget.addEventListener('hidden.bs.modal', () => this.albumModalClosed());
        this.modalTarget.addEventListener('shown.bs.modal', () => this.albumNameTarget.focus());
    }

    async createDispatch(event) {
        event.preventDefault();
        this.dispatchSaveTarget.disabled = true;
        const body = new FormData();
        body.append('_token', this.dispatchTokenValue);
        body.append('name', this.dispatchNameTarget.value);
        try {
            const response = await fetch(this.dispatchUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(Object.values(payload.errors || {}).join(' ') || 'Could not create the dispatch.');
            }
            const option = new Option(`${payload.name} (${payload.idCode})`, String(payload.id));
            option.dataset.idCode = payload.idCode;
            this.dispatchTarget.add(option);
            this.dispatchTarget.value = String(payload.id);
            this.dispatchCodes.set(String(payload.id), payload.code);
            this.dispatchNameTarget.value = '';
            const credentials = document.createElement('div');
            credentials.className = 'alert alert-success mt-3 mb-0';
            credentials.textContent = `${payload.name}: identifier ${payload.idCode} — access code ${payload.code}. Save the access code now; it cannot be displayed again.`;
            this.dispatchCredentialsTarget.append(credentials);
            this.dispatchCredentialElements.set(String(payload.id), credentials);
            this.dispatchMessageTarget.hidden = true;
        } catch (error) {
            this.dispatchMessageTarget.className = 'alert alert-danger mt-3 mb-0';
            this.dispatchMessageTarget.textContent = error.message || 'Network or server error, please retry.';
            this.dispatchMessageTarget.hidden = false;
        } finally {
            this.dispatchSaveTarget.disabled = false;
        }
    }

    albumChanged(event) {
        const select = event.target;
        if (select.value === NEW_ALBUM) {
            this.pendingSelect = select;
            this.albumNameTarget.value = '';
            this.albumDescriptionTarget.value = '';
            this.clearAlbumErrors();
            this.albumModal.show();
            return;
        }
        select.dataset.previous = select.value;
        if (select === this.albumAllTarget) {
            this.applyAlbum();
        }
    }

    albumModalClosed() {
        if (this.pendingSelect) {
            this.pendingSelect.value = this.pendingSelect.dataset.previous ?? '';
            this.pendingSelect = null;
        }
    }

    clearAlbumErrors() {
        this.albumErrorTarget.hidden = true;
        [this.albumNameTarget, this.albumDescriptionTarget].forEach((field) => field.classList.remove('is-invalid'));
    }

    async saveAlbum(event) {
        event.preventDefault();
        this.clearAlbumErrors();
        this.albumSaveTarget.disabled = true;

        const body = new FormData();
        body.append('_token', this.albumTokenValue);
        body.append('name', this.albumNameTarget.value);
        body.append('description', this.albumDescriptionTarget.value);

        try {
            const response = await fetch(this.albumUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));

            if (response.ok) {
                this.addAlbumOption(payload.id, payload.name);
                const select = this.pendingSelect;
                this.pendingSelect = null;
                select.value = String(payload.id);
                select.dataset.previous = select.value;
                if (select === this.albumAllTarget) {
                    this.applyAlbum();
                }
                this.albumModal.hide();
            } else {
                const errors = payload.errors || { name: 'Could not create the album.' };
                Object.keys(errors).forEach((field) => {
                    const input = field === 'description' ? this.albumDescriptionTarget : this.albumNameTarget;
                    input.classList.add('is-invalid');
                });
                this.albumErrorTarget.textContent = Object.values(errors).join(' ');
                this.albumErrorTarget.hidden = false;
            }
        } catch {
            this.albumErrorTarget.textContent = 'Network or server error, please retry.';
            this.albumErrorTarget.hidden = false;
        } finally {
            this.albumSaveTarget.disabled = false;
        }
    }

    addAlbumOption(id, name) {
        this.element.querySelectorAll('select').forEach((select) => {
            const marker = select.querySelector(`option[value="${NEW_ALBUM}"]`);
            if (!marker) {
                return;
            }
            const option = new Option(name, String(id));
            select.insertBefore(option, marker);
        });
    }

    disconnect() {
        this.albumModal.dispose();
        this.dispatchResultModal?.dispose();
        this.items.forEach((item) => URL.revokeObjectURL(item.thumbUrl));
    }

    browse(event) {
        if (event.target !== this.inputTarget) {
            this.inputTarget.click();
        }
    }

    dragOver(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.add('dropzone-active');
    }

    dragLeave() {
        this.dropzoneTarget.classList.remove('dropzone-active');
    }

    drop(event) {
        event.preventDefault();
        this.dragLeave();
        this.addFiles(event.dataTransfer.files);
    }

    pick() {
        this.addFiles(this.inputTarget.files);
        this.inputTarget.value = '';
    }

    addFiles(fileList) {
        Array.from(fileList)
            .filter((file) => ACCEPTED.includes(file.type))
            .forEach((file) => this.addItem(file));
        this.refresh();
    }

    addItem(file) {
        const element = this.templateTarget.content.firstElementChild.cloneNode(true);
        const thumbUrl = URL.createObjectURL(file);
        element.querySelector('.bulk-thumb').src = thumbUrl;
        element.querySelector('[data-role="filename"]').textContent = file.name;
        element.querySelector('[name="title"]').value = file.name.replace(/\.[^.]+$/, '').slice(0, 25);
        element.querySelector('[name="uploadDate"]').value = this.dateAllTarget.value || this.todayValue;

        const album = this.albumSourceTarget.cloneNode(true);
        album.className = 'form-select form-select-sm';
        album.removeAttribute('data-bulk-upload-target');
        album.name = 'album';
        album.value = this.albumAllTarget.value;
        album.dataset.previous = album.value;
        album.addEventListener('change', (event) => this.albumChanged(event));
        element.querySelector('[data-role="album-slot"]').prepend(album);

        const item = { file, element, thumbUrl, done: false };
        element.querySelector('[data-role="remove"]').addEventListener('click', () => this.remove(item));
        this.items.push(item);
        this.listTarget.append(element);
    }

    remove(item) {
        URL.revokeObjectURL(item.thumbUrl);
        item.element.remove();
        this.items = this.items.filter((candidate) => candidate !== item);
        this.refresh();
    }

    clear() {
        [...this.items].forEach((item) => this.remove(item));
        this.summaryTarget.hidden = true;
    }

    applyAlbum() {
        this.pending().forEach((item) => {
            const select = item.element.querySelector('[name="album"]');
            select.value = this.albumAllTarget.value;
            select.dataset.previous = select.value;
        });
    }

    applyDate() {
        if (!this.dateAllTarget.value) {
            return;
        }
        this.pending().forEach((item) => {
            item.element.querySelector('[name="uploadDate"]').value = this.dateAllTarget.value;
        });
    }

    pending() {
        return this.items.filter((item) => !item.done);
    }

    refresh() {
        this.toolbarTarget.hidden = this.items.length === 0;
        this.submitTarget.disabled = this.pending().length === 0 || this.pendingDispatchReset !== null;
    }

    async upload() {
        const queue = this.pending();
        if (queue.length === 0 || this.pendingDispatchReset !== null) {
            return;
        }
        if (this.hasDispatchTarget && !this.dispatchTarget.value) {
            this.dispatchMessageTarget.className = 'alert alert-danger mt-3 mb-0';
            this.dispatchMessageTarget.textContent = 'Select or create a dispatch before uploading.';
            this.dispatchMessageTarget.hidden = false;
            return;
        }

        this.submitTarget.disabled = true;
        if (this.hasDispatchTarget) {
            this.dispatchTarget.disabled = true;
            this.dispatchSaveTarget.disabled = true;
        }
        this.summaryTarget.hidden = true;
        let succeeded = 0;

        for (const item of queue) {
            if (await this.uploadItem(item)) {
                succeeded++;
            }
        }

        const failed = queue.length - succeeded;
        this.summaryTarget.className = failed === 0 ? 'alert alert-success' : 'alert alert-warning';
        this.summaryTarget.textContent = `${succeeded} photo(s) uploaded` + (failed > 0 ? `, ${failed} need your attention.` : '.');
        this.summaryTarget.hidden = false;
        if (this.hasDispatchTarget) {
            if (failed === 0) {
                const id = this.dispatchTarget.value;
                const idCode = this.dispatchTarget.selectedOptions[0].dataset.idCode;
                if (this.dispatchCodes.has(id)) {
                    this.showDispatchResult(idCode, this.dispatchCodes.get(id));
                    this.dispatchCodes.delete(id);
                } else {
                    this.pendingDispatchReset = { id, idCode };
                    await this.retryDispatchReset();
                }
            }
            this.dispatchTarget.disabled = this.pendingDispatchReset !== null;
            this.dispatchSaveTarget.disabled = this.pendingDispatchReset !== null;
        }
        this.refresh();
    }

    async retryDispatchReset() {
        const dispatch = this.pendingDispatchReset;
        if (dispatch === null) {
            return;
        }
        this.dispatchResetRetryTarget.disabled = true;
        const body = new FormData();
        body.append('_token', this.dispatchResetTokenValue);
        try {
            const url = this.dispatchResetUrlValue.replace(/\/0\/code$/, `/${encodeURIComponent(dispatch.id)}/code`);
            const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(Object.values(payload.errors || {}).join(' ') || 'Could not generate the access code.');
            }
            if (payload.idCode !== dispatch.idCode || !/^\d{6}$/.test(payload.code)) {
                throw new Error('Invalid response from the server');
            }
            const credentials = this.dispatchCredentialElements.get(dispatch.id);
            if (credentials) {
                credentials.textContent = `Identifier ${payload.idCode} — updated access code ${payload.code}. Save the access code now; it cannot be displayed again.`;
            }
            this.pendingDispatchReset = null;
            this.dispatchResetRetryTarget.hidden = true;
            this.dispatchMessageTarget.hidden = true;
            this.dispatchTarget.disabled = false;
            this.dispatchSaveTarget.disabled = false;
            this.showDispatchResult(payload.idCode, payload.code);
        } catch (error) {
            this.dispatchMessageTarget.className = 'alert alert-danger mt-3 mb-0';
            this.dispatchMessageTarget.textContent = `Photos uploaded, but the access code could not be confirmed: ${error.message}. Retry code generation.`;
            this.dispatchMessageTarget.hidden = false;
            this.dispatchResetRetryTarget.hidden = false;
        } finally {
            this.dispatchResetRetryTarget.disabled = false;
            this.refresh();
        }
    }

    showDispatchResult(idCode, code) {
        this.dispatchResultIdentifierTarget.textContent = idCode;
        this.dispatchResultCodeTarget.textContent = code;
        this.dispatchResultModal.show();
    }

    async uploadItem(item) {
        const status = item.element.querySelector('[data-role="status"]');
        this.clearErrors(item);
        status.className = 'mt-2 small text-body-secondary';
        status.textContent = 'Uploading…';

        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('file', item.file);
        if (this.hasDispatchTarget) {
            body.append('dispatch', this.dispatchTarget.value);
        }
        item.element.querySelectorAll('input[name], select[name]').forEach((field) => body.append(field.name, field.value));

        try {
            const response = await fetch(this.urlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (response.ok) {
                item.done = true;
                item.element.querySelectorAll('input, select').forEach((field) => (field.disabled = true));
                item.element.querySelector('[data-role="remove"]').hidden = true;
                status.className = 'mt-2 small text-success';
                status.textContent = 'Uploaded ✓';

                return true;
            }

            const payload = await response.json().catch(() => ({}));
            this.showErrors(item, payload.errors || {});
            status.className = 'mt-2 small text-danger';
            status.textContent = 'Not uploaded, please fix the errors.';
        } catch {
            status.className = 'mt-2 small text-danger';
            status.textContent = 'Network or server error, please retry.';
        }

        return false;
    }

    clearErrors(item) {
        item.element.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    }

    showErrors(item, errors) {
        Object.entries(errors).forEach(([name, message]) => {
            const field = item.element.querySelector(`[name="${name}"]`);
            if (field) {
                field.classList.add('is-invalid');
                field.parentElement.querySelector('.invalid-feedback').textContent = message;
            } else {
                item.element.querySelector('[data-role="status"]').textContent = message;
            }
        });
    }
}
