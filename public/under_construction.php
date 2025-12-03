<?php
// under_construction.php
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Page Under Construction</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">


    <style>
        :root {
            --bg: #0f172a;
            --card: #020617;
            --accent: #38bdf8;
            --accent-soft: rgba(56,189,248,0.15);
            --muted: #94a3b8;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1e293b 0, #020617 52%, #000 100%);
            color: #e5e7eb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 12px;
        }

        .uc-wrap {
            max-width: 540px;
            width: 100%;
            background: rgba(15,23,42,0.9);
            border-radius: 18px;
            padding: 24px 22px 20px;
            border: 1px solid rgba(148,163,184,0.35);
            box-shadow:
                0 18px 45px rgba(15,23,42,0.85),
                0 0 0 1px rgba(15,23,42,0.6);
            position: relative;
            overflow: hidden;
        }

        .uc-wrap::before {
            content: "";
            position: absolute;
            inset: -40%;
            background:
                radial-gradient(circle at 0 0, rgba(56,189,248,0.14), transparent 55%),
                radial-gradient(circle at 100% 100%, rgba(129,140,248,0.12), transparent 55%);
            opacity: 0.8;
            pointer-events: none;
        }

        .uc-inner {
            position: relative;
            z-index: 1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(15,23,42,0.9);
            border: 1px solid rgba(148,163,184,0.4);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #e5e7eb;
            margin-bottom: 10px;
        }

        .badge-dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #fbbf24;
            box-shadow: 0 0 0 4px rgba(250,204,21,0.22);
        }

        h1 {
            font-size: 1.6rem;
            margin: 0 0 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h1 span.icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: radial-gradient(circle at 30% 0, #facc15, #ea580c);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #020617;
            font-size: 20px;
            box-shadow: 0 10px 25px rgba(234,88,12,0.32);
        }

        .subtitle {
            color: var(--muted);
            font-size: 0.95rem;
            margin-bottom: 14px;
        }

        .progress-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 14px 0 18px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #cbd5f5;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(15,23,42,0.9);
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid rgba(30,64,175,0.7);
        }

        .progress-fill {
            width: 68%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #22c55e, #a3e635, #facc15);
            box-shadow: 0 0 18px rgba(250,204,21,0.6);
        }

        .info-list {
            list-style: none;
            padding: 0;
            margin: 0 0 18px;
            font-size: 0.85rem;
            color: #cbd5f5;
        }

        .info-list li {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 4px 0;
        }

        .info-bullet {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: rgba(56,189,248,0.9);
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-primary {
            border-radius: 999px;
            padding: 10px 16px;
            background: linear-gradient(90deg,#38bdf8,#6366f1);
            color: #0b1120;
            border: 0;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-outline {
            border-radius: 999px;
            padding: 9px 14px;
            background: rgba(15,23,42,0.9);
            border: 1px solid rgba(148,163,184,0.7);
            color: #e5e7eb;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hint {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>


<main>
    <div class="uc-wrap">
        <div class="uc-inner">
            <div class="badge">
                <span class="badge-dot"></span>
                Page under construction
            </div>

            <h1>
                <span class="icon">🚧</span>
                Coming soon…
            </h1>

            <p class="subtitle">
                We’re still wiring things up here. This page will be live soon with all the details you’re looking for.
            </p>

            <div class="progress-row">
                <div class="progress-label">
                    <span>Progress</span>
                    <span>~70% complete</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
            </div>

            <ul class="info-list">
                <li>
                    <span class="info-bullet"></span>
                    Some features may be missing or look different when we launch.
                </li>
                <li>
                    <span class="info-bullet"></span>
                    If you landed here from a menu, the rest of the site is safe to use.
                </li>
            </ul>

            <div class="cta-row">
                <a href="<?= e(BASE_URL) ?>/attendee/dashboard.php" class="btn-primary">
                    Back to dashboard
                </a>
                <a href="mailto:<?= e('support@example.com') ?>" class="btn-outline">
                    Need something from this page?
                </a>
            </div>

            <div class="hint">
                If this page should be live for an event, please contact the site admin.
            </div>
        </div>
    </div>
</main>


</body>
</html>
