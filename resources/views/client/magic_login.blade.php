<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Required - Expose</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f4f4f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 420px;
            width: 100%;
            border: 1px solid #e4e4e7;
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 32px;
        }

        .logo {
            width: 48px;
            height: 48px;
        }

        .brand {
            margin-left: 16px;
        }

        .brand-name {
            font-size: 24px;
            font-weight: 700;
            color: #18181b;
            letter-spacing: -0.02em;
        }

        .brand-tagline {
            font-size: 12px;
            color: #71717a;
        }

        h1 {
            font-size: 20px;
            font-weight: 600;
            color: #18181b;
            margin-bottom: 8px;
        }

        .description {
            color: #52525b;
            margin-bottom: 24px;
            font-size: 14px;
            line-height: 1.5;
        }

        .error {
            background: #fef2f2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fecaca;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #3f3f46;
            margin-bottom: 8px;
        }

        input[type="email"] {
            width: 100%;
            padding: 12px 16px;
            background: #ffffff;
            border: 1px solid #d4d4d8;
            border-radius: 8px;
            font-size: 15px;
            color: #18181b;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input[type="email"]::placeholder {
            color: #a1a1aa;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: #DE4E78;
            box-shadow: 0 0 0 3px rgba(222, 78, 120, 0.15);
        }

        button {
            width: 100%;
            padding: 12px 16px;
            background: #DE4E78;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            margin-top: 16px;
            transition: background-color 0.2s, transform 0.1s;
        }

        button:hover {
            background: #c43d65;
        }

        button:active {
            transform: scale(0.98);
        }

        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e4e4e7;
        }

        .footer-text {
            font-size: 12px;
            color: #71717a;
        }

        .footer-text a {
            color: #DE4E78;
            text-decoration: none;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://beyondco.de/apps/icons/expose.png" alt="Expose" class="logo">
            <div class="brand">
                <div class="brand-name">Expose</div>
                <div class="brand-tagline">by Beyond Code</div>
            </div>
        </div>

        <h1>Access Required</h1>
        <p class="description">Enter your email address to continue to this site.</p>

        @if($error)
            <div class="error">{{ $error }}</div>
        @endif

        <form method="POST" action="/__expose_magic_login">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="you@example.com" required autofocus>
            <input type="hidden" name="redirect_url" value="{{ $redirectUrl }}">
            <button type="submit">Continue</button>
        </form>

        <div class="footer">
            <p class="footer-text">
                Protected by <a href="https://expose.dev" target="_blank">Expose</a>
            </p>
        </div>
    </div>
</body>
</html>
