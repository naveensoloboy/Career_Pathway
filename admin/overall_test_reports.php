<?php
// admin/overall_test_reports.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

// helper escape (you already have e(), but keeping local esc for clarity)
function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------
// Build overall grouped report
// ---------------------

$dailyGrouped = [];
$weeklyGrouped = [];

// Prefer attempts_tests (detailed mapping). If empty, fallback to attempts.
$sql = "
    SELECT
        at.attempt_id,
        a.user_roll,
        u.full_name,
        u.class,
        t.test_type,
        t.test_date,
        at.test_id AS mapped_test_id,
        at.score,
        at.total_marks
    FROM attempts_tests at
    JOIN attempts a ON a.id = at.attempt_id
    JOIN users u ON u.roll_no = a.user_roll
    JOIN tests t ON t.id = at.test_id
    ORDER BY t.test_date DESC, t.test_type, u.full_name
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// fallback if attempts_tests is empty
if (empty($rows)) {
    $sql2 = "
        SELECT
            a.id AS attempt_id,
            a.user_roll,
            u.full_name,
            u.class,
            t.test_type,
            t.test_date,
            t.id AS mapped_test_id,
            a.score,
            a.total_marks
        FROM attempts a
        JOIN users u ON u.roll_no = a.user_roll
        JOIN tests t ON t.id = a.test_id
        ORDER BY t.test_date DESC, t.test_type, u.full_name
    ";
    $rows = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC);
}

// Temporary aggregation buckets
$dailyTmp  = []; // [date][roll] => ['roll_no','full_name','class','score_sum','total_sum']
$weeklyTmp = []; // [type|date][roll] => same

foreach ($rows as $r) {
    $userRoll = (string)$r['user_roll'];
    $name     = $r['full_name'] ?? '';
    $cls      = $r['class'] ?? '';
    $type     = $r['test_type'] ?? 'daily';
    $date     = $r['test_date'] ?? '';

    $score = isset($r['score']) ? (int)$r['score'] : 0;
    $total = isset($r['total_marks']) ? (int)$r['total_marks'] : 0;

    // Daily grouping (type === 'daily')
    if ($type === 'daily') {
        if (!isset($dailyTmp[$date])) {
            $dailyTmp[$date] = [];
        }
        if (!isset($dailyTmp[$date][$userRoll])) {
            $dailyTmp[$date][$userRoll] = [
                'roll_no'   => $userRoll,
                'full_name' => $name,
                'class'     => $cls,
                'score_sum' => 0,
                'total_sum' => 0,
            ];
        }
        $dailyTmp[$date][$userRoll]['score_sum'] += $score;
        $dailyTmp[$date][$userRoll]['total_sum'] += $total;
    } else {
        // Weekly / other grouping by type|date
        $key = $type . '|' . $date;
        if (!isset($weeklyTmp[$key])) {
            $weeklyTmp[$key] = [];
        }
        if (!isset($weeklyTmp[$key][$userRoll])) {
            $weeklyTmp[$key][$userRoll] = [
                'roll_no'   => $userRoll,
                'full_name' => $name,
                'class'     => $cls,
                'score_sum' => 0,
                'total_sum' => 0,
            ];
        }
        $weeklyTmp[$key][$userRoll]['score_sum'] += $score;
        $weeklyTmp[$key][$userRoll]['total_sum'] += $total;
    }
}

// Convert dailyTmp to dailyGrouped
foreach ($dailyTmp as $date => $byRoll) {
    $items = array_values($byRoll);
    usort($items, function ($a, $b) {
        return strcasecmp($a['full_name'], $b['full_name']);
    });
    $dailyGrouped[$date] = [
        'label' => $date,
        'items' => $items,
    ];
}

// Convert weeklyTmp to weeklyGrouped
foreach ($weeklyTmp as $key => $byRoll) {
    $parts      = explode('|', $key, 2);
    $typeLabel  = ucfirst($parts[0] ?? 'weekly');
    $dateLabel  = $parts[1] ?? '';
    $label      = $typeLabel . ' • ' . $dateLabel;

    $items = array_values($byRoll);
    usort($items, function ($a, $b) {
        return strcasecmp($a['full_name'], $b['full_name']);
    });

    $weeklyGrouped[$key] = [
        'label' => $label,
        'items' => $items,
    ];
}

// Sort groups by date desc
uksort($dailyGrouped, function ($a, $b) {
    return strcmp($b, $a);
});
uksort($weeklyGrouped, function ($ka, $kb) {
    $da = explode('|', $ka, 2)[1] ?? '';
    $db = explode('|', $kb, 2)[1] ?? '';
    return strcmp($db, $da);
});
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Overall Test Reports</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .btn {
            display:inline-block;
            padding:8px 12px;
            border-radius:8px;
            text-decoration:none;
            font-weight:600;
            border:0;
            cursor:pointer;
        }
        .btn-primary {
            background:#2563eb;
            color:#fff;
        }
        .btn-ghost {
            background:transparent;
            border:1px solid #c7defb;
            color:#2563eb;
        }
        .muted {
            color:#64748b;
            font-size:0.95rem;
        }
        /* Printing: only show the report content */
        @media print {
            body * {
                visibility: hidden;
            }
            #overall-report,
            #overall-report * {
                visibility: visible;
            }
            #overall-report {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">

    <!-- Header + actions -->
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <h2>OVERALL TEST REPORT</h2>
        <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="btn btn-primary" onclick="printOverallReport()">Download as PDF</button>
            <a class="btn btn-ghost" href="<?= esc(BASE_URL) ?>/admin/reports.php">Back to Reports</a>
        </div>
    </div>

    <hr style="margin:1.5rem 0; border:0; border-bottom:1px solid #e6eefb;">

    <div id="overall-report" style="display:grid; grid-template-columns:1fr 1fr; gap:18px; align-items:start;">
        <!-- Daily tests section -->
        <section>
            <h4>Daily Tests</h4><br>
            <?php if (empty($dailyGrouped)): ?>
                <div class="muted">No daily attempts found.</div>
            <?php else: ?>
                <?php foreach ($dailyGrouped as $date => $grp): ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-weight:700; margin-bottom:6px;">
                            <?= htmlspecialchars($grp['label'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="background:#fff;border:1px solid #eef2ff;border-radius:8px;padding:8px;overflow:auto;">
                            <table style="width:100%;border-collapse:collapse;">
                                <thead style="color:#64748b;font-weight:700;">
                                    <tr>
                                        <th style="padding:8px;text-align:left">Name</th>
                                        <th style="padding:8px;text-align:left">Roll</th>
                                        <th style="padding:8px;text-align:left">Class</th>
                                        <th style="padding:8px;text-align:right">Score</th>
                                        <th style="padding:8px;text-align:right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($grp['items'] as $row): ?>
                                    <tr>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['roll_no'],   ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['class'],     ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;text-align:right"><strong><?= (int)$row['score_sum'] ?></strong></td>
                                        <td style="padding:8px;text-align:right"><?= (int)$row['total_sum'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Weekly tests section -->
        <section>
            <h4>Weekly Tests</h4><br>
            <?php if (empty($weeklyGrouped)): ?>
                <div class="muted">No weekly attempts found.</div>
            <?php else: ?>
                <?php foreach ($weeklyGrouped as $key => $grp): ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-weight:700; margin-bottom:6px;">
                            <?= htmlspecialchars($grp['label'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="background:#fff;border:1px solid #eef2ff;border-radius:8px;padding:8px;overflow:auto;">
                            <table style="width:100%;border-collapse:collapse;">
                                <thead style="color:#64748b;font-weight:700;">
                                    <tr>
                                        <th style="padding:8px;text-align:left">Name</th>
                                        <th style="padding:8px;text-align:left">Roll</th>
                                        <th style="padding:8px;text-align:left">Class</th>
                                        <th style="padding:8px;text-align:right">Score</th>
                                        <th style="padding:8px;text-align:right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($grp['items'] as $row): ?>
                                    <tr>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['roll_no'],   ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($row['class'],     ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px;text-align:right"><strong><?= (int)$row['score_sum'] ?></strong></td>
                                        <td style="padding:8px;text-align:right"><?= (int)$row['total_sum'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
function printOverallReport() {
    const originalTitle = document.title;
    document.title = 'overall_test_report';
    window.print();
    document.title = originalTitle;
}
</script>

</body>
</html>
