<?php
// attendee/daily_test.php
require_once __DIR__ . '/../config/config.php';
require_login();

$today = new DateTime('today');
$maxDate = $today->format('Y-m-d');
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Test — Attendee</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">

    <style>
        /* Page-Specific Styles */
        body {
            background-color: #f8fafc; /* Light grey background */
            font-family: system-ui, -apple-system, sans-serif;
        }

        .daily-test-container {
            max-width: 480px;
            margin: 4rem auto; /* Vertical spacing */
            padding: 0 1rem;
        }

        /* The Card Component */
        .test-card {
            background: white;
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .test-card h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: #1e293b;
            font-size: 1.5rem;
            font-weight: 700;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
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
            box-sizing: border-box; /* Ensures padding doesn't break width */
            appearance: none; /* Removes default styling on iOS */
        }

        input[type="date"]:focus {
            outline: none;
            border-color: #2563eb; /* Primary Blue */
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Button Styling */
        .btn-start {
            width: 100%;
            padding: 0.875rem;
            background-color: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-start:hover {
            background-color: #1d4ed8;
        }

        .btn-start:active {
            transform: scale(0.98);
        }

        /* Info Alert Box */
        .info-box {
            margin-top: 2rem;
            padding: 1rem;
            background-color: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            color: #1e40af;
            font-size: 0.875rem;
            line-height: 1.5;
            text-align: left;
            display: flex;
            align-items: start;
            gap: 0.5rem;
        }
        
        .info-icon {
            font-size: 1.2em;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="daily-test-container">
    <div class="test-card">
        <h2>📅 Daily Test</h2>
        
        <form method="get" action="<?= e(BASE_URL) ?>/attendee/take_test.php">
            <input type="hidden" name="test_type" value="daily">
            
            <div class="form-group">
                <label for="test_date">Select Test Date</label>
                <input 
                    type="date" 
                    id="test_date" 
                    name="test_date" 
                    max="<?= e($maxDate) ?>" 
                    required
                >
            </div>

            <button type="submit" class="btn-start">Start Test</button>
        </form>

        <div class="info-box">
            <span class="info-icon">ℹ️</span>
            <span>
                <strong>Note:</strong> If you have already attempted the test for the selected date, the system will show you your previous results instead of starting a new test.
            </span>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>