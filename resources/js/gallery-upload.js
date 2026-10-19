const CONCURRENCY = 2;
const MAX_ATTEMPTS = 3;

document.querySelectorAll('[data-gallery-upload]').forEach((root) => {
    const form = root.closest('form');
    const input = root.querySelector('[data-gallery-input]');
    const gallery = root.querySelector('[data-gallery-sortable]');
    const status = root.querySelector('[data-gallery-status]');

    if (!form || !input || !gallery) {
        return;
    }

    const uploadUrl = root.dataset.uploadUrl;
    const discardTemplate = root.dataset.discardTemplate || '';
    const context = root.dataset.galleryContext || 'match';
    const maxBytes = Number(root.dataset.maxBytes || 8 * 1024 * 1024);
    const token = form.querySelector('input[name="_token"]')?.value || '';

    const queue = [];
    let active = 0;
    const idleWaiters = [];
    const files = new WeakMap();

    if (input.name) {
        input.removeAttribute('name');
    }

    const refreshVisibility = () => {
        gallery.classList.toggle('has-items', gallery.querySelector('[data-gallery-item]') !== null);
    };

    const setStatus = (message, isError = false) => {
        if (!status) {
            return;
        }

        status.hidden = message === '';
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    };

    const counts = () => {
        const uploading = gallery.querySelectorAll('.is-uploading').length;
        const failed = gallery.querySelectorAll('.is-upload-error').length;
        const staged = gallery.querySelectorAll('[data-staged]').length;
        const total = uploading + failed + staged;

        return { staged, uploading, failed, total };
    };

    const renderStatus = () => {
        const { staged, uploading, failed, total } = counts();

        if (total === 0) {
            setStatus('');
            return;
        }

        if (failed > 0) {
            setStatus(`Učitano ${staged} od ${total}. ${failed === 1 ? '1 fotografija nije učitana' : `${failed} fotografije nisu učitane`}. Pokušajte ponovo ili ih uklonite.`, true);
            return;
        }

        if (uploading > 0 || queue.length > 0 || active > 0) {
            setStatus(`Učitavanje fotografija: ${staged} od ${total}. Sačekajte kraj prije snimanja.`);
            return;
        }

        setStatus(`Sve nove fotografije su učitane (${staged}). Sada možete sačuvati.`);
    };

    const notifyIdle = () => {
        if (active === 0 && queue.length === 0) {
            idleWaiters.splice(0).forEach((resolve) => resolve());
        }

        renderStatus();
    };

    const whenIdle = () => {
        if (active === 0 && queue.length === 0) {
            return Promise.resolve();
        }

        return new Promise((resolve) => idleWaiters.push(resolve));
    };

    const csrfHeaders = () => ({
        Accept: 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
    });

    const hiddenInput = (name, value) => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        field.value = value;

        return field;
    };

    const finishCard = (figure, payload) => {
        figure.classList.remove('is-uploading', 'is-upload-error');
        figure.dataset.staged = '1';
        figure.draggable = true;
        figure.querySelector('[data-upload-overlay]')?.remove();

        const order = hiddenInput('gallery_order[]', payload.id);
        order.dataset.galleryOrder = 'true';
        figure.prepend(hiddenInput('staged_gallery[]', payload.id));
        figure.insertBefore(order, figure.querySelector('figcaption'));

        const caption = figure.querySelector('figcaption');
        caption.replaceChildren();

        const label = document.createElement('label');
        label.className = 'gallery-caption-field';
        label.append('Opis');
        const captionInput = document.createElement('input');
        captionInput.name = `gallery_captions[${payload.id}]`;
        captionInput.placeholder = 'Opis fotografije';
        label.append(captionInput);

        const actions = document.createElement('span');
        actions.className = 'gallery-item-actions';
        const handle = document.createElement('button');
        handle.className = 'gallery-drag-handle';
        handle.type = 'button';
        handle.dataset.galleryHandle = 'true';
        handle.setAttribute('aria-label', 'Pomjeri sliku');
        handle.textContent = 'Pomjeri';
        const remove = document.createElement('button');
        remove.className = 'gallery-delete-control';
        remove.type = 'button';
        remove.dataset.galleryRemove = 'true';
        remove.textContent = 'Ukloni';
        actions.append(handle, remove);
        caption.append(label, actions);
        refreshVisibility();
    };

    const markFailed = (figure, message) => {
        figure.classList.remove('is-uploading');
        figure.classList.add('is-upload-error');
        const overlay = figure.querySelector('[data-upload-overlay]');
        if (overlay) {
            overlay.textContent = message;
        }

        let retry = figure.querySelector('[data-gallery-retry]');
        if (!retry) {
            retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'gallery-drag-handle';
            retry.dataset.galleryRetry = 'true';
            retry.textContent = 'Pokušaj ponovo';
            figure.querySelector('.gallery-item-actions')?.prepend(retry);
        }

        renderStatus();
    };

    const uploadFile = (file, figure, attempt = 1) => new Promise((resolve) => {
        if (figure.dataset.removed === '1' || !figure.isConnected) {
            resolve();
            return;
        }
        const body = new FormData();
        body.append('image', file);
        body.append('context', context);
        body.append('_token', token);

        const xhr = new XMLHttpRequest();
        figure._xhr = xhr;
        const overlay = figure.querySelector('[data-upload-overlay]');
        if (overlay) {
            overlay.textContent = 'Šalje se…';
        }

        xhr.open('POST', uploadUrl);
        Object.entries(csrfHeaders()).forEach(([key, value]) => xhr.setRequestHeader(key, value));
        xhr.timeout = 120000;

        xhr.upload.addEventListener('progress', (event) => {
            if (!overlay || !event.lengthComputable) {
                return;
            }

            const percent = Math.min(99, Math.round((event.loaded / event.total) * 100));
            overlay.textContent = percent >= 99 ? 'Obrada…' : `Šalje se ${percent}%`;
        });

        xhr.upload.addEventListener('load', () => {
            if (overlay && !figure.classList.contains('is-upload-error')) {
                overlay.textContent = 'Obrada…';
            }
        });

        const fail = (message, canRetry) => {
            if (figure.dataset.removed === '1') {
                resolve();
                return;
            }

            if (canRetry && attempt < MAX_ATTEMPTS) {
                window.setTimeout(() => {
                    uploadFile(file, figure, attempt + 1).then(resolve);
                }, 800 * attempt);
                return;
            }

            markFailed(figure, message);
            resolve();
        };

        xhr.addEventListener('load', () => {
            if (figure.dataset.removed === '1') {
                resolve();
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    finishCard(figure, JSON.parse(xhr.responseText));
                } catch {
                    fail('Odgovor servera nije ispravan.', true);
                    return;
                }
                resolve();
                return;
            }

            let message = 'Učitavanje nije uspjelo.';
            try {
                const payload = JSON.parse(xhr.responseText);
                message = payload.errors?.image?.[0] || payload.errors?.context?.[0] || payload.message || message;
            } catch {
                message = xhr.status === 413 ? 'Fotografija je prevelika.' : message;
            }

            const canRetry = xhr.status >= 500 || xhr.status === 429;
            fail(message, canRetry);
        });

        xhr.addEventListener('error', () => fail('Veza je prekinuta. Pokušajte ponovo.', true));
        xhr.addEventListener('timeout', () => fail('Obrada traje predugo. Pokušajte ponovo.', true));
        xhr.addEventListener('abort', () => resolve());
        xhr.send(body);
    });

    const pump = () => {
        while (active < CONCURRENCY && queue.length > 0) {
            const job = queue.shift();
            active += 1;
            job().finally(() => {
                active -= 1;
                notifyIdle();
                pump();
            });
        }

        notifyIdle();
    };

    const enqueue = (file) => {
        if (!file.type.startsWith('image/')) {
            setStatus(`"${file.name}" nije fotografija. Dozvoljene su JPG, PNG i WebP.`, true);
            return;
        }

        if (file.size > maxBytes) {
            const mb = Math.round(maxBytes / (1024 * 1024));
            setStatus(`"${file.name}" je veća od ${mb} MB.`, true);
            return;
        }

        const figure = document.createElement('figure');
        figure.className = 'admin-gallery-item is-uploading';
        figure.dataset.galleryItem = 'true';
        figure.draggable = false;
        files.set(figure, file);

        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        figure._blobUrl = image.src;

        const overlay = document.createElement('div');
        overlay.className = 'gallery-upload-overlay';
        overlay.dataset.uploadOverlay = 'true';
        overlay.textContent = 'Čeka se…';

        const caption = document.createElement('figcaption');
        const actions = document.createElement('span');
        actions.className = 'gallery-item-actions';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'gallery-delete-control';
        remove.dataset.galleryRemove = 'true';
        remove.textContent = 'Ukloni';
        actions.append(remove);
        caption.append(actions);
        figure.append(image, overlay, caption);
        gallery.append(figure);
        refreshVisibility();

        queue.push(() => uploadFile(file, figure));
        renderStatus();
        pump();
    };

    const removeCard = (figure) => {
        figure.dataset.removed = '1';
        figure._xhr?.abort();
        if (figure._blobUrl) {
            URL.revokeObjectURL(figure._blobUrl);
        }
        figure.remove();
        refreshVisibility();
        renderStatus();
    };

    const discardCard = async (figure) => {
        const id = figure.querySelector('[name="staged_gallery[]"]')?.value;
        if (!id) {
            removeCard(figure);
            return;
        }

        figure.querySelector('[data-gallery-remove]')?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(discardTemplate.replace('__MEDIA__', id), {
                method: 'DELETE',
                headers: csrfHeaders(),
            });

            if (!response.ok && response.status !== 404) {
                figure.querySelector('[data-gallery-remove]')?.removeAttribute('disabled');
                setStatus('Fotografija nije uklonjena. Pokušajte ponovo.', true);
                return;
            }
        } catch {
            figure.querySelector('[data-gallery-remove]')?.removeAttribute('disabled');
            setStatus('Fotografija nije uklonjena. Provjerite vezu i pokušajte ponovo.', true);
            return;
        }

        removeCard(figure);
    };

    input.addEventListener('change', () => {
        const selected = Array.from(input.files || []);
        input.value = '';
        selected.forEach(enqueue);
    });

    root.addEventListener('click', (event) => {
        const retry = event.target.closest('[data-gallery-retry]');
        if (retry) {
            const figure = retry.closest('[data-gallery-item]');
            const file = figure ? files.get(figure) : null;
            if (!figure || !file) {
                return;
            }

            figure.classList.remove('is-upload-error');
            figure.classList.add('is-uploading');
            retry.remove();
            queue.push(() => uploadFile(file, figure));
            renderStatus();
            pump();
            return;
        }

        const remove = event.target.closest('[data-gallery-remove]');
        if (!remove || !root.contains(remove)) {
            return;
        }

        const figure = remove.closest('[data-gallery-item]');
        if (figure) {
            discardCard(figure);
        }
    });

    form.addEventListener('submit', (event) => {
        const failed = gallery.querySelector('.is-upload-error');
        const busy = active > 0 || queue.length > 0 || gallery.querySelector('.is-uploading');

        if (failed) {
            event.preventDefault();
            renderStatus();
            status?.scrollIntoView({ block: 'nearest' });
            return;
        }

        if (busy) {
            event.preventDefault();
            if (form.dataset.galleryWaiting === '1') {
                return;
            }

            form.dataset.galleryWaiting = '1';
            const button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
                button.dataset.galleryLabel = button.textContent;
                button.textContent = 'Učitavanje fotografija…';
            }

            whenIdle().then(() => {
                form.dataset.galleryWaiting = '';
                if (button) {
                    button.disabled = false;
                    button.textContent = button.dataset.galleryLabel || button.textContent;
                }

                if (gallery.querySelector('.is-upload-error')) {
                    renderStatus();
                    return;
                }

                form.requestSubmit();
            });
            return;
        }

        form.dataset.galleryAllowLeave = '1';
    });

    window.addEventListener('beforeunload', (event) => {
        if (form.dataset.galleryAllowLeave === '1') {
            return;
        }

        const dirty = active > 0
            || queue.length > 0
            || gallery.querySelector('.is-uploading, [data-staged]');

        if (!dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    refreshVisibility();
});
