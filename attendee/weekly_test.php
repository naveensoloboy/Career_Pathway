<?php
// attendee/weekly_test.php
require_once __DIR__ . '/../config/config.php';
require_login();

// calculate last Saturday (if today is Saturday, allow it)
$today = new DateTime('today');
$dow = (int)$today->format('N'); // 1=Mon .. 7=Sun

// find last Saturday (N=6)
$lastSaturday = clone $today;
if ($dow === 6) {
    // today is Saturday; allow it
    $lastSaturday = $today;
} else {
    // modify to previous saturday
    $lastSaturday->modify('last saturday');
}
$maxDate = $lastSaturday->format('Y-m-d');

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weekly Test — Attendee</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">
    
    <style>
        /* Page-Specific Styles */
        body {
            background-color: #f8fafc;
            font-family: system-ui, -apple-system, sans-serif;
        }

        .weekly-test-container {
            max-width: 480px;
            margin: 4rem auto;
            padding: 0 1rem;
        }

        .test-card {
            background: white;
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 5px solid #7c3aed; /* Purple accent for Weekly to differ from Daily */
        }

        .test-card h2 {
            margin-top: 0;
            margin-bottom: 0.5rem;
            color: #1e293b;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .subtitle {
            color: #64748b;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #475569;
            font-size: 0.9rem;
            font-weight: 600;
        }

        input[type="date"] {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background-color: #fff;
            color: #334155;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
            appearance: none;
        }

        input[type="date"]:focus {
            outline: none;
            border-color: #7c3aed; /* Purple accent */
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .btn-start {
            width: 100%;
            padding: 0.875rem;
            background-color: #7c3aed; /* Purple accent */
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-start:hover {
            background-color: #6d28d9;
        }

        .btn-start:active {
            transform: scale(0.98);
        }

        /* Warning/Tip styling */
        .tip-box {
            background-color: #fff1f2;
            border: 1px solid #ffe4e6;
            color: #be123c;
            padding: 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="weekly-test-container">
    <div class="test-card">
        <h2>Weekly Challenge</h2>
        <p class="subtitle">Select a Saturday to begin your assessment</p>

        <form method="get" action="<?= e(BASE_URL) ?>/attendee/take_test.php">
            <input type="hidden" name="test_type" value="weekly">
            
            <div class="form-group">
                <label for="test_date">Select Saturday</label>
                <input 
                    type="date" 
                    id="test_date" 
                    name="test_date" 
                    max="<?= e($maxDate) ?>" 
                    required
                >
                
                <div class="tip-box">
                    <span>⚠️</span>
                    <span><strong>Important:</strong> You must select a Saturday. Other days are invalid.</span>
                </div>
            </div>

            <button type="submit" class="btn-start">Load Weekly Test</button>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>