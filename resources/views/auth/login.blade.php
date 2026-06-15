@extends('layouts.portal')
@section('content')
<section class="section">
    <div class="container-news" style="max-width:460px;">
        <h1>Prijava u CMS</h1>
        @if($errors->any())<div class="flash">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('login') }}" class="panel">@csrf
            <div class="field"><label>Email</label><input type="email" name="email" required autocomplete="email"></div>
            <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
            <label><input type="checkbox" name="remember" value="1"> Zapamti me</label>
            <div style="margin-top:16px"><button class="btn" type="submit">Prijava</button></div>
            <p class="meta">Demo: admin@milosevac.test / password</p>
        </form>
    </div>
</section>
@endsection
