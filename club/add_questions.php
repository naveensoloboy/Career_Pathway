<?php
// club/add_questions.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];
$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : intval($_POST['club_id'] ?? 0);
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : intval($_POST['test_id'] ?? 0);

if ($club_id <= 0) die('Club not specified.');

// role check
$role = get_club_role($pdo, $user_roll, $club_id);
if (!$role) die('You are not a member of this club.');

if (!$role['can_post_questions'] && !is_admin($pdo)) {
    die('You do not have posting privileges. Request admin to enable posting.');
}

$errors = [];
$successCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        // Expect arrays for each field
        $q_texts = $_POST['question_text'] ?? [];
        $opt_a = $_POST['option_a'] ?? [];
        $opt_b = $_POST['option_b'] ?? [];
        $opt_c = $_POST['option_c'] ?? [];
        $opt_d = $_POST['option_d'] ?? [];
        $corrects = $_POST['correct_option'] ?? [];
        $test_ids = $_POST['test_id_arr'] ?? []; // optional per-question test id (or blank)

        // sanitize sizes
        $count = max(
            count($q_texts),
            count($opt_a),
            count($opt_b),
            count($opt_c),
            count($opt_d),
            count($corrects)
        );

        if ($count === 0) {
            $errors[] = 'No questions submitted.';
        } else {
            // prepare statements outside loop
            $insQ = $pdo->prepare('INSERT INTO questions_pending (club_id, posted_by_roll, question_text, test_id) VALUES (:cid, :posted, :qt, :tid)');
            $insOpt = $pdo->prepare('INSERT INTO questions_pending_options (pending_question_id, option_a, option_b, option_c, option_d, correct_option) VALUES (:pid, :a, :b, :c, :d, :co)');

            try {
                $pdo->beginTransaction();
                for ($i = 0; $i < $count; $i++) {
                    // trim values (treat missing as empty string)
                    $qt = trim($q_texts[$i] ?? '');
                    $a  = trim($opt_a[$i] ?? '');
                    $b  = trim($opt_b[$i] ?? '');
                    $c  = trim($opt_c[$i] ?? '');
                    $d  = trim($opt_d[$i] ?? '');
                    $co = intval($corrects[$i] ?? 0);
                    // allow per-question test id or default club-level test_id param
                    $tid = intval($test_ids[$i] ?? 0) ?: ($test_id ?: null);

                    // validation per question
                    $qErrs = [];
                    if ($qt === '') $qErrs[] = "Question #" . ($i + 1) . " is empty.";
                    if ($a === '' || $b === '' || $c === '' || $d === '') $qErrs[] = "All four options are required for question #" . ($i + 1) . ".";
                    if ($co < 1 || $co > 4) $qErrs[] = "Correct option must be 1-4 for question #" . ($i + 1) . ".";

                    if (!empty($qErrs)) {
                        // append and skip this question (do not abort whole batch)
                        foreach ($qErrs as $qe) $errors[] = $qe;
                        continue;
                    }

                    // insert pending question
                    $insQ->execute([
                        ':cid' => $club_id,
                        ':posted' => $user_roll,
                        ':qt' => $qt,
                        ':tid' => $tid ?: null
                    ]);
                    $pendingId = $pdo->lastInsertId();

                    // insert options
                    $insOpt->execute([
                        ':pid' => $pendingId,
                        ':a' => $a,
                        ':b' => $b,
                        ':c' => $c,
                        ':d' => $d,
                        ':co' => $co
                    ]);

                    $successCount++;
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'DB error: ' . $e->getMessage();
            }
        }
    }
}

// fetch tests for this club to optionally attach
$tests = $pdo->prepare('SELECT id, title, test_type, test_date FROM tests WHERE club_id = :cid ORDER BY test_date DESC');
$tests->execute([':cid' => $club_id]);
$testsList = $tests->fetchAll();

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Add Question — Club</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/create_club.css">
    <style>
        .q-block { border:1px solid rgba(15,23,42,0.04); padding:12px; border-radius:10px; margin-bottom:12px; background:#fff; }
        .q-actions { text-align:right; margin-top:8px; }
        .remove-btn { background:#ef4444;color:#fff;padding:6px 10px;border-radius:8px;border:0; cursor:pointer; }
        .add-btn { background:linear-gradient(90deg,#2563eb,#7c3aed); color:#fff; padding:10px 12px;border-radius:10px;border:0; cursor:pointer; font-weight:700;}
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container" style="max-width:800px;">
    <h2>Add Questions (pending approval)</h2>

    <?php if ($errors): ?>
        <script>alert(<?= json_encode(implode("\n", $errors)) ?>);</script>
    <?php endif; ?>

    <?php if ($successCount): ?>
        <script>alert(<?= json_encode($successCount . " question(s) submitted for approval.") ?>);</script>
    <?php endif; ?>

    <form method="post" id="multi-questions-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">

        <label>Attach to Test (optional)</label><br>
        <select id="form-test-select" name="global_test_id">
            <option value="">-- no test (general) --</option>
            <?php foreach ($testsList as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $test_id ? 'selected' : '' ?>>
                    <?= e($t['title'] . ' (' . $t['test_type'] . ' - ' . $t['test_date'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="small">If you want different questions to attach to different tests, set per-question test in each block.</p>

        <div id="questions-container">
            <!-- one template question block (first visible) -->
            <div class="q-block" data-index="0">
                <h3 class="q-number" style="margin: 15px 0 25px 0;">Question 1</h3>
                
                <label>Attach to Test (this question)</label>
                <select name="test_id_arr[0]">
                    <option value="">-- no test --</option>
                    <?php foreach ($testsList as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $test_id ? 'selected' : '' ?>>
                            <?= e($t['title'] . ' (' . $t['test_type'] . ' - ' . $t['test_date'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                
                <label>Question</label>
                <textarea name="question_text[0]" rows="3" required></textarea>

                <label>Option A</label>
                <input type="text" name="option_a[0]" required>

                <label>Option B</label>
                <input type="text" name="option_b[0]" required>

                <label>Option C</label>
                <input type="text" name="option_c[0]" required>

                <label>Option D</label>
                <input type="text" name="option_d[0]" required>

                <label>Correct Option</label>
                <select name="correct_option[0]" required>
                    <option value="">-- choose --</option>
                    <option value="1">A</option>
                    <option value="2">B</option>
                    <option value="3">C</option>
                    <option value="4">D</option>
                </select>

                <div class="q-actions">
                    <button type="button" class="remove-btn" onclick="removeBlock(this)" style="display:none;">Remove</button>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:10px; margin:12px 0;">
            <button type="button" id="add-question" class="add-btn">+ Add another question</button>
            <button type="submit" class="add-btn" style="background:linear-gradient(90deg,#16a34a,#10b981);">Submit all</button>
        </div>
    </form>
</main>

<script>
(function(){
    const container = document.getElementById('questions-container');
    const addBtn = document.getElementById('add-question');
    const globalTest = document.getElementById('form-test-select');

    // helper: current number of blocks
    function nextIndex() {
        return container.querySelectorAll('.q-block').length;
    }

    // remove a block (ensure at least one remains)
    function removeBlock(btn) {
        const block = btn.closest('.q-block');
        if (!block) return;
        if (container.querySelectorAll('.q-block').length === 1) {
            alert('At least one question is required.');
            return;
        }
        block.remove();
        reindexBlocks();
    }
    window.removeBlock = removeBlock;

    // reindex names and update Question N labels
    function reindexBlocks() {
        const blocks = container.querySelectorAll('.q-block');
        blocks.forEach((b, idx) => {
            b.setAttribute('data-index', idx);

            // update question number label
            const qNumLabel = b.querySelector('.q-number');
            if (qNumLabel) qNumLabel.textContent = "Question " + (idx + 1);

            // rename inputs/selects/textareas to correct index
            b.querySelectorAll('textarea, input[type="text"], select').forEach(el => {
                const name = el.getAttribute('name');
                if (!name) return;
                // replace first numeric index in square brackets
                const newName = name.replace(/\[\d+\]/, '[' + idx + ']');
                el.setAttribute('name', newName);
            });
        });
    }

    // create a new block by cloning template and cleaning values
    function addNewBlock() {
        const idx = nextIndex();
        const template = container.querySelector('.q-block').outerHTML;
        const temp = document.createElement('div');
        temp.innerHTML = template;
        const newBlock = temp.firstElementChild;
        newBlock.setAttribute('data-index', idx);

        // update names and clear text fields, but set per-question select to globalTest value if available
        newBlock.querySelectorAll('textarea, input[type="text"]').forEach(el => {
            const name = el.getAttribute('name') || '';
            el.setAttribute('name', name.replace(/\[\d+\]/g, '[' + idx + ']'));
            el.value = '';
        });

        // update selects: set proper name and set value to globalTest.value if present
        newBlock.querySelectorAll('select').forEach(sel => {
            const name = sel.getAttribute('name') || '';
            sel.setAttribute('name', name.replace(/\[\d+\]/g, '[' + idx + ']'));
            // prefer global test id if user hasn't set per-question
            if (globalTest && globalTest.value) {
                sel.value = globalTest.value;
            } else {
                // keep the select's first option (no change) so it won't overwrite default
                sel.selectedIndex = 0;
            }
            // mark as not user-set
            sel.removeAttribute('data-user-set');
        });

        // show Remove button (first block keeps remove hidden)
        const rem = newBlock.querySelector('.remove-btn');
        if (rem) rem.style.display = 'inline-block';

        container.appendChild(newBlock);
        reindexBlocks();
    }

    // initial: if there is a global test selected, apply it to existing per-question selects
    function applyGlobalToExisting() {
        const v = globalTest ? globalTest.value : '';
        if (!v) return;
        container.querySelectorAll('select[name^="test_id_arr"]').forEach(s => {
            // only apply if user hasn't manually changed the per-question select
            if (!s.dataset.userSet) s.value = v;
        });
    }

    // when the page loads: add second block (if not already) and sync defaults
    document.addEventListener('DOMContentLoaded', function(){
        // ensure at least two blocks: if only one exists, add another
        const initialCount = nextIndex();
        if (initialCount < 2) {
            addNewBlock();
        }
        // apply global selection to all per-question selects
        applyGlobalToExisting();
    });

    // attach add button
    addBtn.addEventListener('click', addNewBlock);

    // when global test changes, apply to per-question selects that are not user-set
    if (globalTest) {
        globalTest.addEventListener('change', function () {
            applyGlobalToExisting();
        });
    }

    // mark per-question select as user-set when changed by the user
    container.addEventListener('change', function (e) {
        if (e.target && e.target.name && e.target.name.indexOf('test_id_arr') === 0) {
            e.target.dataset.userSet = '1';
        }
    });

})();
</script>


<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
