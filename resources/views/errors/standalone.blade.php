{{-- 5xx page: no database, settings or session access, so it renders even when those are down. --}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }} - Maestro Touch Legal</title>
    <style>
        body { margin: 0; font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; color: #364151; background: #e7f6ff; line-height: 1.6; }
        main { max-width: 640px; margin: 0 auto; padding: 18vh 24px; }
        p.code { color: #007bf8; font-weight: 600; font-size: 14px; margin: 0; }
        h1 { color: #0f172a; font-size: 36px; line-height: 1.2; font-weight: 600; margin: 8px 0 16px; }
        a { display: inline-block; margin-top: 24px; background: #007bf8; color: #fff; padding: 14px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <p class="code">Error {{ $code }}</p>
        <h1>{{ $heading }}</h1>
        <p>{{ $text }}</p>
        <a href="/">Back to Home</a>
    </main>
</body>
</html>
