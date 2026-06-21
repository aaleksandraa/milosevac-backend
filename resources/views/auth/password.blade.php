@extends(auth()->user()->hasRole('author', 'contributor') ? 'layouts.author' : 'layouts.admin')

@section('content')
    <section class="panel" style="max-width:620px;">
        <h1>Moj nalog</h1>
        <p class="meta">Uredite osnovne podatke za prijavu i po potrebi promijenite lozinku.</p>

        <form method="post" action="{{ route('account.profile.update') }}" style="margin-top:18px;">
            @csrf
            @method('put')

            <div class="field">
                <label for="name">Ime</label>
                <input id="name" type="text" name="name" value="{{ old('name', auth()->user()->name) }}" required autocomplete="name">
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="field">
                <label for="email">Email adresa</label>
                <input id="email" type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required autocomplete="email">
                @error('email')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <button class="btn" type="submit">Sacuvaj podatke</button>
        </form>
    </section>

    <section class="panel" style="max-width:620px; margin-top:18px;">
        <h2>Promjena lozinke</h2>
        <p class="meta">Unesite trenutnu lozinku, zatim novu lozinku od najmanje 12 karaktera.</p>

        <form method="post" action="{{ route('account.password.update') }}" style="margin-top:18px;">
            @csrf
            @method('put')

            <div class="field">
                <label for="current_password">Trenutna lozinka</label>
                <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                @error('current_password')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="field">
                <label for="password">Nova lozinka</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password')<small class="field-error">{{ $message }}</small>@enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Potvrda nove lozinke</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button class="btn" type="submit">Sacuvaj novu lozinku</button>
        </form>
    </section>
@endsection
