<?php
// admin/delete_club.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Invalid request.');
}

$club_id = isset($_POST['club_id']) ? intval($_POST['club_id']) : 0;
if ($club_id <= 0) {
    http_response_code(400);
    die('Missing club id.');
}

try {
    $pdo->beginTransaction();

    // Prevent delete if tests exist for club
    $chk = $pdo->prepare('SELECT COUNT(*) FROM tests WHERE club_id = :cid');
    $chk->execute([':cid' => $club_id]);
    $testsCount = (int)$chk->fetchColumn();

    if ($testsCount > 0) {
        throw new Exception("Cannot delete club: it has {$testsCount} test(s). Remove tests first.");
    }

    // Optionally check other dependent tables (club_roles, questions, etc.)
    // Example: remove club_roles and club admin entries if you want cascade:
    // $pdo->prepare('DELETE FROM club_roles WHERE club_id = :cid')->execute([':cid' => $club_id]);

    // delete the club
    $del = $pdo->prepare('DELETE FROM clubs WHERE id = :id LIMIT 1');
    $del->execute([':id' => $club_id]);

    if ($del->rowCount() === 0) {
        throw new Exception('Club not found or delete failed.');
    }

    $pdo->commit();
    // redirect back with success message (simple query param)
    header('Location: ' . BASE_URL . '/admin/manage_clubs.php?msg=' . urlencode('Club deleted.'));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: ' . BASE_URL . '/admin/manage_clubs.php?err=' . urlencode($e->getMessage()));
    exit;
}
