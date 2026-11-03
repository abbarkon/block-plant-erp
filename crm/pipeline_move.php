<?php
/**
 * Lightweight endpoint used by the pipeline board drag-and-drop.
 * Accepts POST id + stage and moves an opportunity between stages.
 * Responds with JSON so the board can update without a full reload.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

header('Content-Type: application/json; charset=utf-8');

function pipeline_json($ok, $payload = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $payload));
    exit;
}

if (empty($_SESSION['user_id'])) {
    pipeline_json(false, ['error' => 'Not authenticated.'], 401);
}
if (!has_permission('crm', 'edit')) {
    pipeline_json(false, ['error' => 'You do not have permission to move opportunities.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pipeline_json(false, ['error' => 'POST required.'], 405);
}

$stages = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
$id = (int) ($_POST['id'] ?? 0);
$stage = (string) ($_POST['stage'] ?? '');

if ($id <= 0) {
    pipeline_json(false, ['error' => 'Invalid opportunity id.'], 422);
}
if (!in_array($stage, $stages, true)) {
    pipeline_json(false, ['error' => 'Invalid stage.'], 422);
}

$stmt = $pdo->prepare("SELECT id FROM sales_pipeline WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    pipeline_json(false, ['error' => 'Opportunity not found.'], 404);
}

$status = in_array($stage, ['won', 'lost'], true) ? 'closed' : 'active';
$pdo->prepare("UPDATE sales_pipeline SET stage = ?, status = ? WHERE id = ?")->execute([$stage, $status, $id]);
log_activity('update', 'crm', $id, "Stage changed to {$stage} (board)");

set_flash('success', 'Stage updated.');
pipeline_json(true, ['id' => $id, 'stage' => $stage, 'status' => $status]);
