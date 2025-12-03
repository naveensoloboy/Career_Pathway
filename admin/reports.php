<?php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reports</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/dashboard.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 900px;
            margin: 30px auto;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
        }
        
       
        
        
    </style>
</head>

<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="wrap">
    <h2>Reports</h2>
    <p style="color:#64748b; margin-bottom:20px;">Choose a report type to view detailed analysis.</p>

    <div class="cards">

        <div class="card">
            <h3>Overall Test Reports</h3>
            <p>View overall performance summaries for daily and weekly tests.</p>
            <a class="btn" href="<?= BASE_URL ?>/admin/overall_test_reports.php">Open</a>
        </div>

        <div class="card">
            <h3>Club-Wise Reports</h3>
            <p>Analyze test performance grouped by individual clubs.</p>
            <a class="btn" href="<?= BASE_URL ?>/admin/club_wise_reports.php">Open</a>
        </div>

    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
