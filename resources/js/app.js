import './bootstrap';

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-mobile-toggle]');
    if (toggle) {
        document.querySelector('[data-mobile-nav]')?.classList.toggle('is-open');
    }
});

document.querySelectorAll('[data-slug-source]').forEach((source) => {
    const target = document.querySelector(source.dataset.slugSource);
    source.addEventListener('input', () => {
        if (target && !target.dataset.touched) {
            target.value = source.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }
    });
    target?.addEventListener('input', () => target.dataset.touched = '1');
});

const dateInputs = document.querySelectorAll('[data-date-picker]');

function parseDisplayDate(value) {
    const match = String(value || '').trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*(\d{1,2})?:?(\d{2})?/);
    if (!match) {
        return null;
    }

    const [, day, month, year, hour = '00', minute = '00'] = match;
    return {
        date: `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`,
        time: `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}`,
    };
}

function formatDisplayDate(date, time) {
    if (!date) {
        return '';
    }

    const [year, month, day] = date.split('-');
    return `${day}.${month}.${year}. ${time || '00:00'}`;
}

function closeDatePicker() {
    document.querySelector('[data-date-picker-popover]')?.remove();
}

function openDatePicker(input) {
    closeDatePicker();

    const current = parseDisplayDate(input.value);
    const now = new Date();
    const fallbackDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    const fallbackTime = `${String(now.getHours()).padStart(2, '0')}:00`;
    const popover = document.createElement('div');
    popover.className = 'date-picker-popover';
    popover.dataset.datePickerPopover = 'true';
    popover.innerHTML = `
        <div class="date-picker-grid">
            <label>Datum<input type="date" data-date-picker-date></label>
            <label>Vrijeme<input type="time" data-date-picker-time step="60"></label>
        </div>
        <div class="date-picker-actions">
            <button type="button" class="btn secondary" data-date-picker-clear>Očisti</button>
            <button type="button" class="btn" data-date-picker-apply>Primijeni</button>
        </div>
    `;

    document.body.appendChild(popover);
    const dateField = popover.querySelector('[data-date-picker-date]');
    const timeField = popover.querySelector('[data-date-picker-time]');
    dateField.value = current?.date || fallbackDate;
    timeField.value = current?.time || fallbackTime;

    const rect = input.getBoundingClientRect();
    const width = Math.min(340, window.innerWidth - 24);
    const left = Math.min(Math.max(12, rect.left + window.scrollX), window.scrollX + window.innerWidth - width - 12);
    popover.style.width = `${width}px`;
    popover.style.left = `${left}px`;
    popover.style.top = `${rect.bottom + window.scrollY + 8}px`;

    const apply = () => {
        input.value = formatDisplayDate(dateField.value, timeField.value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        closeDatePicker();
    };

    popover.querySelector('[data-date-picker-apply]').addEventListener('click', apply);
    popover.querySelector('[data-date-picker-clear]').addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        closeDatePicker();
    });
    dateField.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') apply();
    });
    timeField.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') apply();
    });

    setTimeout(() => dateField.focus(), 0);
}

dateInputs.forEach((input) => {
    input.autocomplete = 'off';
    input.addEventListener('focus', () => openDatePicker(input));
    input.addEventListener('click', () => openDatePicker(input));
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-date-picker]') && !event.target.closest('[data-date-picker-popover]')) {
        closeDatePicker();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeDatePicker();
    }
});

document.querySelectorAll('[data-content-type]').forEach((select) => {
    const form = select.closest('form');
    const newsFields = form?.querySelectorAll('[data-news-fields]') || [];
    const powerFields = [
        ...(form?.querySelectorAll('[data-power-outage-fields]') || []),
        ...Array.from(form?.querySelectorAll('[name="notice_schedule"]') || []).map((field) => field.closest('.field')).filter(Boolean),
    ];

    const defaultOption = select.querySelector('option[value=""]');
    if (defaultOption) {
        defaultOption.textContent = 'Vijest';
    }

    const toggleContentFields = () => {
        const isPowerOutage = select.value === 'power_outage';

        newsFields.forEach((field) => {
            field.hidden = isPowerOutage;
            field.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = isPowerOutage;
            });
        });

        powerFields.forEach((field) => {
            field.hidden = !isPowerOutage;
            field.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !isPowerOutage;
            });
        });
    };

    select.addEventListener('change', toggleContentFields);
    toggleContentFields();
});

const richEditors = document.querySelectorAll('[data-rich-editor]');

if (richEditors.length > 0) {
    import('@ckeditor/ckeditor5-build-classic').then(({ default: ClassicEditor }) => {
        richEditors.forEach((textarea) => {
            ClassicEditor.create(textarea, {
                toolbar: [
                    'heading', '|',
                    'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', '|',
                    'insertTable', 'undo', 'redo',
                ],
                link: {
                    addTargetToExternalLinks: true,
                    defaultProtocol: 'https://',
                },
                table: {
                    contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'],
                },
            }).catch(() => {
                textarea.removeAttribute('hidden');
            });
        });
    });
}

document.querySelectorAll('[data-gallery-sortable]').forEach((gallery) => {
    let draggedItem = null;

    const updateOrder = () => {
        gallery.querySelectorAll('[data-gallery-item]').forEach((item) => {
            const input = item.querySelector('[data-gallery-order]');
            if (input) {
                input.disabled = item.classList.contains('is-marked-delete');
            }
        });
    };

    gallery.addEventListener('dragstart', (event) => {
        const item = event.target.closest('[data-gallery-item]');
        if (!item || item.classList.contains('is-marked-delete')) {
            event.preventDefault();
            return;
        }

        draggedItem = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', '');
    });

    gallery.addEventListener('dragover', (event) => {
        if (!draggedItem) {
            return;
        }

        event.preventDefault();
        const item = event.target.closest('[data-gallery-item]');
        if (!item || item === draggedItem || item.classList.contains('is-marked-delete')) {
            return;
        }

        const rect = item.getBoundingClientRect();
        const isBefore = event.clientY < rect.top + rect.height / 2
            || (Math.abs(event.clientY - (rect.top + rect.height / 2)) < rect.height / 3
                && event.clientX < rect.left + rect.width / 2);

        gallery.insertBefore(draggedItem, isBefore ? item : item.nextSibling);
        updateOrder();
    });

    gallery.addEventListener('dragend', () => {
        draggedItem?.classList.remove('is-dragging');
        draggedItem = null;
        updateOrder();
    });

    gallery.addEventListener('change', (event) => {
        const checkbox = event.target.closest('[data-gallery-delete]');
        if (!checkbox) {
            return;
        }

        const item = checkbox.closest('[data-gallery-item]');
        item?.classList.toggle('is-marked-delete', checkbox.checked);
        if (item) {
            item.draggable = !checkbox.checked;
        }
        updateOrder();
    });

    updateOrder();
});

document.querySelectorAll('[data-match-gallery]').forEach((gallery) => {
    const button = gallery.parentElement?.querySelector('[data-match-gallery-more]');
    const batch = Number(gallery.dataset.galleryBatch || 60);

    const updateButton = () => {
        if (button && gallery.querySelectorAll('[data-match-gallery-item].is-hidden').length === 0) {
            button.hidden = true;
        }
    };

    button?.addEventListener('click', () => {
        Array.from(gallery.querySelectorAll('[data-match-gallery-item].is-hidden'))
            .slice(0, batch)
            .forEach((item) => item.classList.remove('is-hidden'));

        updateButton();
    });

    updateButton();
});

const lightboxTriggers = document.querySelectorAll('[data-lightbox-image]');

if (lightboxTriggers.length > 0) {
    const lightbox = document.createElement('div');
    lightbox.className = 'image-lightbox';
    lightbox.dataset.lightbox = 'true';
    lightbox.hidden = true;
    lightbox.innerHTML = `
        <button class="image-lightbox-close" type="button" data-lightbox-close aria-label="Zatvori prikaz">×</button>
        <button class="image-lightbox-nav image-lightbox-prev" type="button" data-lightbox-prev aria-label="Prethodna slika">‹</button>
        <button class="image-lightbox-nav image-lightbox-next" type="button" data-lightbox-next aria-label="Sljedeća slika">›</button>
        <figure class="image-lightbox-frame">
            <img src="" alt="" data-lightbox-img>
        </figure>
    `;
    document.body.appendChild(lightbox);

    const triggers = Array.from(lightboxTriggers);
    const image = lightbox.querySelector('[data-lightbox-img]');
    const closeButton = lightbox.querySelector('[data-lightbox-close]');
    const previousButton = lightbox.querySelector('[data-lightbox-prev]');
    const nextButton = lightbox.querySelector('[data-lightbox-next]');
    let currentIndex = 0;
    let touchStartX = null;

    const closeLightbox = () => {
        lightbox.hidden = true;
        document.body.classList.remove('has-image-lightbox');
        if (image) {
            image.removeAttribute('src');
            image.alt = '';
        }
    };

    const showLightboxImage = (index) => {
        currentIndex = (index + triggers.length) % triggers.length;
        const trigger = triggers[currentIndex];
        const src = trigger.dataset.lightboxImage;
        const text = trigger.dataset.lightboxCaption || trigger.querySelector('img')?.alt || '';
        if (!src || !image) {
            return;
        }

        image.src = src;
        image.alt = text;
    };

    const openLightbox = (trigger) => {
        const index = triggers.indexOf(trigger);
        showLightboxImage(index >= 0 ? index : 0);
        lightbox.hidden = false;
        document.body.classList.add('has-image-lightbox');
        closeButton?.focus();
    };

    const showPreviousImage = () => showLightboxImage(currentIndex - 1);
    const showNextImage = () => showLightboxImage(currentIndex + 1);

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => openLightbox(trigger));
    });

    closeButton?.addEventListener('click', closeLightbox);
    previousButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        showPreviousImage();
    });
    nextButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        showNextImage();
    });
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });
    lightbox.addEventListener('touchstart', (event) => {
        touchStartX = event.changedTouches[0]?.clientX ?? null;
    }, { passive: true });
    lightbox.addEventListener('touchend', (event) => {
        if (touchStartX === null) {
            return;
        }

        const touchEndX = event.changedTouches[0]?.clientX ?? touchStartX;
        const deltaX = touchStartX - touchEndX;
        touchStartX = null;

        if (Math.abs(deltaX) < 40) {
            return;
        }

        if (deltaX > 0) {
            showNextImage();
        } else {
            showPreviousImage();
        }
    }, { passive: true });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !lightbox.hidden) {
            closeLightbox();
        }

        if (event.key === 'ArrowLeft' && !lightbox.hidden) {
            showPreviousImage();
        }

        if (event.key === 'ArrowRight' && !lightbox.hidden) {
            showNextImage();
        }
    });
}

const consentKey = 'milosevac_cookie_consent_v1';
const banner = document.querySelector('[data-cookie-banner]');
const analyticsToggle = document.querySelector('[data-cookie-analytics]');
const marketingToggle = document.querySelector('[data-cookie-marketing]');
const consentVersion = 2;

function readConsent() {
    try {
        return JSON.parse(localStorage.getItem(consentKey) || 'null');
    } catch {
        return null;
    }
}

function writeConsent(analytics, marketing) {
    const value = {
        necessary: true,
        analytics: Boolean(analytics),
        marketing: Boolean(marketing),
        savedAt: new Date().toISOString(),
        version: consentVersion,
    };
    localStorage.setItem(consentKey, JSON.stringify(value));
    return value;
}

function loadGoogleAnalytics() {
    const id = window.milosevacAnalytics?.googleAnalyticsId;
    if (!id || document.querySelector('[data-google-analytics-loader]')) {
        return;
    }

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    script.dataset.googleAnalyticsLoader = 'true';
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        window.dataLayer.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', id, {
        anonymize_ip: true,
    });
}

function loadGoogleAds() {
    const ads = window.milosevacAds;
    if (!ads?.enabled || !ads?.clientId || document.querySelector('[data-google-ads-loader]')) {
        return;
    }

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(ads.clientId)}`;
    script.crossOrigin = 'anonymous';
    script.dataset.googleAdsLoader = 'true';
    document.head.appendChild(script);

    document.querySelectorAll('.adsbygoogle').forEach(() => {
        try {
            window.adsbygoogle = window.adsbygoogle || [];
            window.adsbygoogle.push({});
        } catch {}
    });
}

function applyConsent(consent) {
    if (analyticsToggle) {
        analyticsToggle.checked = Boolean(consent?.analytics);
    }
    if (marketingToggle) {
        marketingToggle.checked = Boolean(consent?.marketing);
    }
    if (consent?.analytics) {
        loadGoogleAnalytics();
    }
    if (consent?.marketing) {
        loadGoogleAds();
    }
}

function hideCookieBanner() {
    banner?.setAttribute('hidden', '');
}

function showCookieBanner() {
    banner?.removeAttribute('hidden');
}

const existingConsent = readConsent();
if (existingConsent) {
    applyConsent(existingConsent);
    if ((existingConsent.version || 1) < consentVersion) {
        showCookieBanner();
    }
} else {
    showCookieBanner();
}

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-cookie-accept]')) {
        const consent = writeConsent(true, true);
        applyConsent(consent);
        hideCookieBanner();
    }

    if (event.target.closest('[data-cookie-essential]')) {
        const consent = writeConsent(false, false);
        applyConsent(consent);
        hideCookieBanner();
    }

    if (event.target.closest('[data-cookie-save]')) {
        const consent = writeConsent(Boolean(analyticsToggle?.checked), Boolean(marketingToggle?.checked));
        applyConsent(consent);
        hideCookieBanner();
    }

    if (event.target.closest('[data-open-cookie-settings]')) {
        applyConsent(readConsent());
        showCookieBanner();
    }
});
