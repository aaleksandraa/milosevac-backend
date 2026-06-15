<div class="cookie-banner" data-cookie-banner hidden>
    <div class="cookie-card" role="dialog" aria-live="polite" aria-labelledby="cookie-title" aria-describedby="cookie-description">
        <div>
            <p class="cookie-kicker">Privatnost i kolačići</p>
            <h2 id="cookie-title">Vaš izbor za kolačiće</h2>
            <p id="cookie-description">
                Koristimo neophodne kolačiće za rad portala. Analitiku i Google oglase učitavamo samo ako ih prihvatite.
            </p>
            <div class="cookie-toggles">
                <label>
                    <input type="checkbox" checked disabled>
                    <span>Neophodni kolačići</span>
                    <small>Uvijek aktivni</small>
                </label>
                <label>
                    <input type="checkbox" data-cookie-analytics>
                    <span>Analitika</span>
                    <small>Google Analytics nakon pristanka</small>
                </label>
                <label>
                    <input type="checkbox" data-cookie-marketing>
                    <span>Marketing i oglasi</span>
                    <small>Google oglasi nakon pristanka</small>
                </label>
            </div>
            <p class="cookie-links">
                <a href="{{ route('privacy') }}">Politika privatnosti</a>
                <a href="{{ route('cookies') }}">Politika kolačića</a>
            </p>
        </div>
        <div class="cookie-actions">
            <button class="btn secondary" type="button" data-cookie-essential>Samo neophodni</button>
            <button class="btn secondary" type="button" data-cookie-save>Sačuvaj izbor</button>
            <button class="btn" type="button" data-cookie-accept>Prihvati sve</button>
        </div>
    </div>
</div>
