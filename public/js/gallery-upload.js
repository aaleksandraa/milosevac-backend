(function () {
    const CONCURRENCY = 2;
    const MAX_ATTEMPTS = 8;

    document.querySelectorAll('[data-gallery-upload]').forEach((root) => {
        if (root.dataset.galleryBound === '1') {
            return;
        }

        const form = root.closest('form');
        const input = root.querySelector('[data-gallery-input]');
        const gallery = root.querySelector('[data-gallery-sortable]');
        const panel = root.querySelector('[data-gallery-panel]');
        const status = root.querySelector('[data-gallery-status]');
        const count = root.querySelector('[data-gallery-count]');
        const bar = root.querySelector('[data-gallery-bar]');

        if (!form || !input || !gallery || !panel) {
            return;
        }

        root.dataset.galleryBound = '1';
        root.classList.add('is-ready');

        const uploadUrl = root.dataset.uploadUrl;
        const discardTemplate = root.dataset.discardTemplate || '';
        const context = root.dataset.galleryContext || 'match';
        const maxBytes = Number(root.dataset.maxBytes || 8 * 1024 * 1024);
        const token = form.querySelector('input[name="_token"]')?.value || '';
        const saveButtons = () => [...form.querySelectorAll('button.btn[type="submit"]')];
        const primarySave = saveButtons().find((button) => !button.hasAttribute('data-gallery-save'));
        const barSave = root.querySelector('[data-gallery-save]');
        const saveNote = root.querySelector('[data-gallery-save-note]');
        if (primarySave && barSave) {
            barSave.textContent = primarySave.textContent.trim();
        }
        saveButtons().forEach((button) => {
            if (!button.dataset.galleryLabel) {
                button.dataset.galleryLabel = button.textContent.trim();
            }
        });

        const queue = [];
        let active = 0;
        const idleWaiters = [];
        const files = new WeakMap();
        const skippedNames = [];

        const refreshVisibility = () => {
            gallery.classList.toggle('has-items', gallery.querySelector('[data-gallery-item]') !== null);
        };

        const counts = () => {
            const uploading = gallery.querySelectorAll('.is-uploading').length;
            const failed = gallery.querySelectorAll('.is-upload-error').length;
            const staged = gallery.querySelectorAll('[data-staged]').length;

            return { staged, uploading, failed, total: uploading + failed + staged };
        };

        const overallPercent = () => {
            const cards = [...gallery.querySelectorAll('.is-uploading, .is-upload-error, [data-staged]')];
            if (cards.length === 0) {
                return 0;
            }

            const sum = cards.reduce((total, card) => {
                if (card.hasAttribute('data-staged')) {
                    return total + 100;
                }
                if (card.classList.contains('is-upload-error')) {
                    return total;
                }

                return total + Number(card.dataset.progress || 0);
            }, 0);

            return Math.max(0, Math.min(100, Math.round(sum / cards.length)));
        };

        const renderStatus = () => {
            const { staged, uploading, failed, total } = counts();
            const percent = overallPercent();
            const busy = uploading > 0 || queue.length > 0 || active > 0;

            panel.hidden = total === 0 && skippedNames.length === 0;
            panel.classList.toggle('is-error', failed > 0 || (total === 0 && skippedNames.length > 0));
            panel.classList.toggle('is-done', total > 0 && !busy && failed === 0);
            if (count) {
                count.textContent = total === 0 ? '' : `${staged} / ${total}`;
            }
            if (bar) {
                bar.style.width = `${percent}%`;
            }

            const skipNote = skippedNames.length
                ? ` Preskočeno ${skippedNames.length} (nisu JPG, PNG ili WebP, ili su prevelike).`
                : '';
            let message = '';
            let buttonLabel = '';
            let lockSave = false;

            if (failed > 0) {
                message = `Učitano ${staged} od ${total}. ${failed === 1 ? '1 fotografija nije učitana' : `${failed} fotografije nisu učitane`}. Pokušajte ponovo ili ih uklonite.${skipNote}`;
                buttonLabel = 'Ispravite fotografije';
                lockSave = true;
            } else if (busy) {
                message = percent >= 99
                    ? `Obrada fotografija: ${staged} od ${total}. Sačekajte kraj prije snimanja.`
                    : `Učitavanje fotografija: ${staged} od ${total} (${percent}%).`;
                buttonLabel = `Učitavanje ${staged}/${total}…`;
                lockSave = true;
            } else if (total > 0) {
                message = `Učitano ${staged} fotografija. Ako ste birali više, dodajte ostatak ili cijeli folder — ove ostaju.${skipNote}`;
            } else if (skippedNames.length > 0) {
                message = `Nijedna fotografija nije dodata.${skipNote}`;
            }

            if (status) {
                status.textContent = message;
            }
            if (saveNote) {
                saveNote.textContent = message || 'Dodajte fotografije. One se učitavaju odmah, a snimate kad traka dođe do kraja.';
            }
            saveButtons().forEach((button) => {
                button.disabled = lockSave;
                button.textContent = lockSave ? buttonLabel : (button.dataset.galleryLabel || button.textContent);
            });
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

        const setCardProgress = (figure, percent, label) => {
            figure.dataset.progress = String(percent);
            const overlay = figure.querySelector('[data-upload-label]');
            const mini = figure.querySelector('[data-upload-mini]');
            if (overlay) {
                overlay.textContent = label;
            }
            if (mini) {
                mini.style.width = `${percent}%`;
            }
            renderStatus();
        };

        const finishCard = (figure, payload) => {
            figure.classList.remove('is-uploading', 'is-upload-error');
            figure.dataset.staged = '1';
            figure.dataset.progress = '100';
            figure.draggable = true;
            figure.querySelector('[data-upload-overlay]')?.remove();
            figure.querySelector('.gallery-upload-name')?.remove();

            const image = figure.querySelector('img') || document.createElement('img');
            image.alt = payload.name || image.alt || '';
            const preview = payload.thumb || payload.url;
            if (preview) {
                image.src = preview;
                image.loading = 'lazy';
                image.decoding = 'async';
            }
            if (!image.parentNode) {
                figure.prepend(image);
            }

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
            setCardProgress(figure, Number(figure.dataset.progress || 0), message);

            if (!figure.querySelector('[data-gallery-retry]')) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'gallery-drag-handle';
                retry.dataset.galleryRetry = 'true';
                retry.textContent = 'Pokušaj ponovo';
                figure.querySelector('.gallery-item-actions')?.prepend(retry);
            }
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
            setCardProgress(figure, 0, 'Šalje se…');

            xhr.open('POST', uploadUrl);
            Object.entries(csrfHeaders()).forEach(([key, value]) => xhr.setRequestHeader(key, value));
            xhr.timeout = 120000;

            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) {
                    return;
                }

                const percent = Math.min(99, Math.round((event.loaded / event.total) * 100));
                setCardProgress(figure, percent, percent >= 99 ? 'Obrada…' : `Šalje se ${percent}%`);
            });

            xhr.upload.addEventListener('load', () => {
                if (!figure.classList.contains('is-upload-error')) {
                    setCardProgress(figure, 99, 'Obrada…');
                }
            });

            const fail = (message, canRetry, statusCode = 0) => {
                if (figure.dataset.removed === '1') {
                    resolve();
                    return;
                }

                if (canRetry && attempt < MAX_ATTEMPTS) {
                    setCardProgress(figure, Number(figure.dataset.progress || 0), 'Ponovni pokušaj…');
                    const wait = statusCode === 429 ? 2500 * attempt : 800 * attempt;
                    window.setTimeout(() => {
                        uploadFile(file, figure, attempt + 1).then(resolve);
                    }, wait);
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

                fail(message, xhr.status >= 500 || xhr.status === 429, xhr.status);
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

        const isAllowedImage = (file) => {
            const type = String(file.type || '').toLowerCase();
            if (type === 'image/jpeg' || type === 'image/jpg' || type === 'image/pjpeg' || type === 'image/png' || type === 'image/webp') {
                return true;
            }

            if (type.startsWith('image/')) {
                return false;
            }

            return /\.(jpe?g|png|webp)$/i.test(file.name || '');
        };

        const enqueue = (file, fragment) => {
            if (!isAllowedImage(file)) {
                skippedNames.push(file.name || 'datoteka');
                return false;
            }

            if (file.size > maxBytes) {
                skippedNames.push(file.name || 'datoteka');
                return false;
            }

            const figure = document.createElement('figure');
            figure.className = 'admin-gallery-item is-uploading';
            figure.dataset.galleryItem = 'true';
            figure.dataset.progress = '0';
            figure.draggable = false;
            files.set(figure, file);

            const name = document.createElement('span');
            name.className = 'gallery-upload-name';
            name.textContent = file.name;

            const overlay = document.createElement('div');
            overlay.className = 'gallery-upload-overlay';
            overlay.dataset.uploadOverlay = 'true';
            const label = document.createElement('span');
            label.dataset.uploadLabel = 'true';
            label.textContent = 'Čeka se…';
            const mini = document.createElement('span');
            mini.className = 'gallery-upload-mini';
            const miniBar = document.createElement('span');
            miniBar.dataset.uploadMini = 'true';
            mini.append(miniBar);
            overlay.append(label, mini);

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
            figure.append(name, overlay, caption);
            fragment.append(figure);

            queue.push(() => uploadFile(file, figure));
            return true;
        };

        const addFiles = (list) => {
            const selected = Array.from(list || []);
            if (selected.length === 0) {
                return;
            }

            const fragment = document.createDocumentFragment();
            let accepted = 0;
            selected.forEach((file) => {
                if (enqueue(file, fragment)) {
                    accepted += 1;
                }
            });
            gallery.append(fragment);
            refreshVisibility();

            panel.hidden = false;
            panel.classList.remove('is-error', 'is-done');
            if (status) {
                const handed = selected.length === 1 ? '1 datoteku' : `${selected.length} datoteka`;
                status.textContent = accepted > 0
                    ? `Preglednik je predao ${handed}. Učitava se ${accepted}.`
                    : `Preglednik je predao ${handed}, ali nijedna nije JPG, PNG ili WebP.`;
            }
            panel.scrollIntoView({ block: 'nearest' });
            renderStatus();
            pump();
        };

        const bindPicker = (picker) => {
            picker?.addEventListener('change', () => {
                const selected = Array.from(picker.files || []);
                picker.value = '';
                addFiles(selected);
            });
        };

        bindPicker(input);
        bindPicker(root.querySelector('[data-gallery-folder]'));

        root.addEventListener('dragover', (event) => {
            if (!event.dataTransfer || ![...event.dataTransfer.types].includes('Files')) {
                return;
            }

            event.preventDefault();
            root.classList.add('is-drop');
        });

        root.addEventListener('dragleave', (event) => {
            if (!root.contains(event.relatedTarget)) {
                root.classList.remove('is-drop');
            }
        });

        root.addEventListener('drop', (event) => {
            if (!event.dataTransfer) {
                return;
            }

            event.preventDefault();
            root.classList.remove('is-drop');
            addFiles(event.dataTransfer.files);
        });

        const removeCard = (figure) => {
            figure.dataset.removed = '1';
            figure._xhr?.abort();
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
                    status.textContent = 'Fotografija nije uklonjena. Pokušajte ponovo.';
                    panel.hidden = false;
                    panel.classList.add('is-error');
                    return;
                }
            } catch {
                figure.querySelector('[data-gallery-remove]')?.removeAttribute('disabled');
                status.textContent = 'Fotografija nije uklonjena. Provjerite vezu i pokušajte ponovo.';
                panel.hidden = false;
                panel.classList.add('is-error');
                return;
            }

            removeCard(figure);
        };

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
                panel.scrollIntoView({ block: 'nearest' });
                return;
            }

            if (busy) {
                event.preventDefault();
                if (form.dataset.galleryWaiting === '1') {
                    return;
                }

                form.dataset.galleryWaiting = '1';
                whenIdle().then(() => {
                    form.dataset.galleryWaiting = '';
                    renderStatus();
                    if (gallery.querySelector('.is-upload-error')) {
                        panel.scrollIntoView({ block: 'nearest' });
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
        renderStatus();
    });
}());
