@extends('layouts.portal')

@section('content')
<section class="legal-page">
    <div class="container-news legal-layout">
        <aside class="legal-side">
            <span>Pravno</span>
            <h1>Politika privatnosti</h1>
            <p>Zadnje ažuriranje: {{ now()->format('d.m.Y.') }}</p>
        </aside>
        <article class="legal-content">
            <p>Portal obrađuje minimalnu količinu podataka potrebnu za rad, sigurnost, komunikaciju sa redakcijom, mjerenje posjeta i prikaz oglasa kada korisnik prihvati odgovarajuće kolačiće.</p>

            <h2>Ko je voditelj obrade</h2>
            <p>Voditelj obrade je redakcija portala. Kontakt za pitanja privatnosti: <a href="mailto:redakcija@milosevac.com">redakcija@milosevac.com</a>.</p>

            <h2>Koje podatke obrađujemo</h2>
            <ul>
                <li>tehničke podatke potrebne za isporuku stranice i sigurnost servera;</li>
                <li>podatke koje sami pošaljete putem e-maila ili kontakt forme;</li>
                <li>agregirane analitičke podatke, samo nakon pristanka na analitičke kolačiće;</li>
                <li>podatke potrebne za prikaz Google oglasa, samo nakon pristanka na marketing i oglase;</li>
                <li>podatke korisničkih naloga u CMS-u za autore i urednike.</li>
            </ul>

            <h2>Google servisi</h2>
            <p>Google Search Console koristi se za provjeru vlasništva i indeksiranja portala i ne traži cookie banner za posjetioce. Google Analytics se učitava tek nakon pristanka na analitičke kolačiće. Google oglasi se učitavaju tek nakon pristanka na marketing i oglase.</p>

            <h2>Pravna osnova</h2>
            <p>Neophodna obrada zasniva se na legitimnom interesu za sigurnost i funkcionisanje portala. Analitika i marketing/oglasi zasnivaju se na vašem pristanku, koji možete povući u svakom trenutku kroz postavke kolačića.</p>

            <h2>Vaša prava</h2>
            <p>Možete zatražiti pristup, ispravku, brisanje, ograničenje obrade ili prigovor, u skladu sa primjenjivim propisima o zaštiti podataka. Za zahtjeve nas kontaktirajte putem e-maila.</p>

            <h2>Rokovi čuvanja</h2>
            <p>Kontakt poruke čuvamo koliko je potrebno za komunikaciju. Sigurnosni logovi čuvaju se ograničeno. Cookie izbor čuva se u vašem browseru do 6 mjeseci, osim ako ga ranije obrišete.</p>

            <p class="legal-note">Ovaj tekst je tehnička priprema portala i treba ga uskladiti sa stvarnim podacima pravnog subjekta prije produkcije.</p>
        </article>
    </div>
</section>
@endsection
