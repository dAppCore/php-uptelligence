<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Core PHP Framework</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        h1 {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .version {
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .links {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        a {
            color: #667eea;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border: 1px solid #667eea;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        a:hover {
            background: #667eea;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Core PHP Framework</h1>
        <p class="version">Laravel {{ Illuminate\Foundation\Application::VERSION }} | PHP {{ PHP_VERSION }}</p>
        <div class="links">
            <a href="https://github.com/host-uk/core-php">Documentation</a>
            <a href="/admin">Admin Panel</a>
            <a href="/api/docs">API Docs</a>
        </div>
    </div>
</body>
</html>
