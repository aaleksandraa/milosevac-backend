@if(file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@else
    <style>
        :root {
            --brand: #9f1d1d;
            --brand-deep: #651313;
            --accent: #ea6a11;
            --ink: #231b1b;
            --muted: #6e6464;
            --line: #eadfdf;
            --surface: #fff;
            --soft: #f8f3f3;
        }

        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            background: var(--surface);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.6;
        }
        a { color: inherit; text-decoration: none; }
        button, input, textarea, select { font: inherit; }
        .container-news { width: min(1120px, calc(100% - 32px)); margin-inline: auto; }
        .section { padding-block: 34px; }
        .panel,
        .stat {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 14px 40px -30px rgba(35, 27, 27, .35);
            padding: 18px;
        }
        .field { display: grid; gap: 6px; margin-bottom: 14px; }
        .field label { font-weight: 900; }
        .field input,
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 11px 12px;
            background: #fff;
            color: var(--ink);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 0;
            border-radius: 8px;
            padding: 10px 15px;
            background: var(--brand);
            color: #fff;
            font-weight: 900;
            cursor: pointer;
        }
        .btn.secondary {
            background: #fff;
            color: var(--ink);
            border: 1px solid rgba(255,255,255,.18);
        }
        .flash {
            background: #ecfdf3;
            border: 1px solid #b8e8c7;
            border-radius: 8px;
            margin-bottom: 16px;
            padding: 10px 12px;
            font-weight: 750;
        }
        .meta { color: var(--muted); font-size: 13px; }

        .auth-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 16px;
            background:
                linear-gradient(135deg, rgba(159,29,29,.94), rgba(35,27,27,.98)),
                radial-gradient(circle at 20% 15%, rgba(234,106,17,.35), transparent 34%);
        }
        .auth-card {
            width: min(100%, 460px);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 30px 80px -34px rgba(0,0,0,.55);
            padding: 30px;
        }
        .auth-brand { margin-bottom: 22px; }
        .auth-brand a { color: var(--brand); font-weight: 950; font-size: 15px; }
        .auth-card h1 { margin: 6px 0 4px; font-size: 30px; line-height: 1.1; }
        .auth-card p { margin: 0; color: var(--muted); }
        .auth-actions { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-top: 16px; }
        .remember-field { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 750; }

        .admin-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            min-height: 100vh;
            background: #f7f3f3;
        }
        .admin-nav {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            background: #201818;
            color: #fff;
            padding: 22px;
        }
        .admin-nav h1 { margin: 0 0 18px; font-size: 24px; }
        .admin-nav a {
            display: block;
            border-radius: 8px;
            padding: 10px 12px;
            color: rgba(255,255,255,.88);
            font-weight: 850;
        }
        .admin-nav a:hover { background: rgba(255,255,255,.08); color: #fff; }
        .admin-nav form { margin-top: 18px; }
        .admin-main { min-width: 0; padding: 28px; }
        .admin-main h1 { margin-top: 0; line-height: 1.15; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .stat strong { display: block; font-size: 32px; line-height: 1; }
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 22px; align-items: start; }
        .table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
        }
        .table th,
        .table td {
            border-bottom: 1px solid var(--line);
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }

        @media (max-width: 860px) {
            .admin-shell { grid-template-columns: 1fr; }
            .admin-nav { position: static; height: auto; }
            .admin-main { padding: 18px; }
            .stats,
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
@endif
