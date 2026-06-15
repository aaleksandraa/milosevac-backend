@extends('layouts.portal')

@section('content')
<section class="legal-page">
    <div class="container-news legal-layout">
        <aside class="legal-side">
            <span>Privatnost</span>
            <h1>Politika kolačića</h1>
            <p>U svakom trenutku možete promijeniti izbor u footeru.</p>
        </aside>
        <article class="legal-content">
            <h2>Šta su kolačići</h2>
            <p>Kolačići su male tekstualne datoteke koje web stranica sprema u browser kako bi zapamtila tehničke postavke, izbor korisnika ili, uz pristanak, pomogla u analizi korištenja stranice.</p>

            <h2>Kategorije koje koristimo</h2>
            <div class="cookie-table">
                <div><strong>Neophodni</strong><span>Čuvanje cookie izbora, sigurnost sesije, rad prijave i osnovne funkcije portala.</span><em>Uvijek aktivni</em></div>
                <div><strong>Analitika</strong><span>Mjerenje posjeta i popularnosti sadržaja putem Google Analytics-a nakon pristanka.</span><em>Opcionalno</em></div>
                <div><strong>Marketing i oglasi</strong><span>Prikaz Google oglasa putem AdSense/Google Ads skripte nakon pristanka.</span><em>Opcionalno</em></div>
            </div>

            <h2>Vaš izbor</h2>
            <p>Možete prihvatiti sve kolačiće, zadržati samo neophodne ili sačuvati vlastiti izbor. Ako ne prihvatite analitiku ili marketing, pripadajuće Google skripte se ne učitavaju.</p>
            <button class="btn" type="button" data-open-cookie-settings>Otvori postavke kolačića</button>

            <h2>Google servisi</h2>
            <p>Kada se doda Google Analytics ID u `.env`, analitika će se učitavati samo nakon pristanka. Tipični Google Analytics kolačići uključuju `_ga` i `_ga_*`, uz trajanje koje zavisi od Google konfiguracije.</p>
            <p>Kada administrator poveže Google AdSense publisher ID i slotove oglasa, Google oglasi se učitavaju samo nakon pristanka na marketing i oglase.</p>

            <p class="legal-note">Prije produkcije tekst treba uskladiti sa stvarnim pravnim subjektom, Google nalogom i pravilima privatnosti koja će portal objaviti.</p>
        </article>
    </div>
</section>
@endsection
