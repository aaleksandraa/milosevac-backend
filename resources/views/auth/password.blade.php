@extends(auth()->user()->hasRole('author', 'contributor') ? 'layouts.author' : 'layouts.admin')

@section('content')
    <section class="panel" style="max-width:620px;">
        <h1>Promjena lozinke</h1>
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
