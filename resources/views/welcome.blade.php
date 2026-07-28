<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'SheetCollab') }}</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: #f6f1e8;
                font-family: "Segoe UI", sans-serif;
            }

            a {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 14px 18px;
                border-radius: 14px;
                background: #b4572e;
                color: #fff;
                font-weight: 700;
                text-decoration: none;
                box-shadow: 0 18px 46px rgba(31, 26, 18, 0.08);
            }
        </style>
    </head>
    <body>
        <a href="/admin">Войти в админку</a>
    </body>
</html>
