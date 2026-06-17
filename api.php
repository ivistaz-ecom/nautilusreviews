<?php
/**
 * JSW Seafarer Staff Feedback Survey — PHP REST API
 *
 * Endpoints
 * ---------
 *  GET    /api/survey/staff?form_code=JSW        List active staff for a form
 *  POST   /api/survey/submit                     Submit a full survey response
 *  GET    /api/survey/responses                  List responses (admin)
 *  GET    /api/survey/responses/{reg_no}         Fetch one submission by reg_no
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');               // lock down in production
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

// ── Router ────────────────────────────────────────────────────────────────────
// Uses ?action= query param — works on shared hosting without .htaccess
// GET  api.php?action=staff&form_code=JSW
// POST api.php?action=submit
// GET  api.php?action=responses&form_code=JSW
// GET  api.php?action=responses&reg_no=JSW-20240617-000001
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

try {
    if      ($method === 'GET'  && $action === 'staff')     { handleGetStaff();         }
    elseif  ($method === 'POST' && $action === 'submit')    { handleSubmit();            }
    elseif  ($method === 'GET'  && $action === 'responses') {
        $regNo = !empty($_GET['reg_no']) ? trim($_GET['reg_no']) : null;
        handleGetResponses($regNo);
    }
    else { sendJson(['error' => 'Invalid action. Use ?action=staff|submit|responses'], 404); }

} catch (Throwable $e) {
    error_log('[JSW Survey API] ' . $e->getMessage());
    sendJson(['error' => 'Internal server error'], 500);
}

// ── GET /api/survey/staff?form_code=JSW ──────────────────────────────────────
/**
 * Returns active staff for a given form_code.
 * The HTML calls this on page load to build the Step 2 checkboxes.
 */
function handleGetStaff(): void
{
    $formCode = trim($_GET['form_code'] ?? '');
    if ($formCode === '') {
        sendJson(['error' => 'form_code query parameter is required'], 422);
    }

    $pdo  = getPDO();

    // Verify the form exists
    $fStmt = $pdo->prepare("SELECT form_name FROM forms WHERE form_code = ? AND is_active = 1");
    $fStmt->execute([$formCode]);
    $form  = $fStmt->fetch();
    if (!$form) {
        sendJson(['error' => 'Unknown or inactive form_code: ' . $formCode], 404);
    }

    $stmt = $pdo->prepare("
        SELECT id, staff_key, full_name, role
        FROM   staff
        WHERE  form_code = ? AND is_active = 1
        ORDER  BY sort_order, full_name
    ");
    $stmt->execute([$formCode]);

    sendJson([
        'form_code' => $formCode,
        'form_name' => $form['form_name'],
        'staff'     => $stmt->fetchAll(),
    ]);
}

// ── POST /api/survey/submit ───────────────────────────────────────────────────
/**
 * Expected JSON body:
 * {
 *   "form_code"     : "JSW",
 *   "vessel"        : "JSW MIHIRGAD",
 *   "seafarer_rank" : "Master / Captain",
 *   "staff_ratings" : [
 *     {
 *       "staff_id"               : 1,          <-- integer id from staff table
 *       "rating_responsiveness"  : 4,
 *       "rating_resolution"      : 5,
 *       "rating_professionalism" : 5,
 *       "rating_knowledge"       : 4,
 *       "rating_overall"         : 5,
 *       "what_did_well"          : "Always reachable.",
 *       "areas_to_improve"       : "",
 *       "specific_incident"      : ""
 *       "other_feedback"         : ""
 *     }
 *   ]
 * }
 *
 * Returns:
 * { "success": true, "reg_no": "JSW-20240617-000001", "rated_staff_count": 2 }
 */
function handleSubmit(): void
{
    $body = getJsonBody();

    // ── Validate top-level fields ─────────────────────────────────────────────
    $formCode     = trim($body['form_code']     ?? '');
    $vessel       = trim($body['vessel']        ?? '');
    $seafarerRank = trim($body['seafarer_rank'] ?? 'Not specified');
    $staffRatings = $body['staff_ratings']      ?? [];

    if ($formCode === '')  sendJson(['error' => 'form_code is required'], 422);
    if ($vessel   === '')  sendJson(['error' => 'vessel is required'],    422);
    if (!is_array($staffRatings) || count($staffRatings) === 0) {
        sendJson(['error' => 'staff_ratings must contain at least one entry'], 422);
    }

    $pdo = getPDO();

    // Verify form exists
    $fStmt = $pdo->prepare("SELECT form_code FROM forms WHERE form_code = ? AND is_active = 1");
    $fStmt->execute([$formCode]);
    if (!$fStmt->fetch()) {
        sendJson(['error' => 'Unknown or inactive form_code: ' . $formCode], 422);
    }

    // ── Validate staff IDs belong to this form ────────────────────────────────
    $incomingIds = array_map('intval', array_column($staffRatings, 'staff_id'));
    $incomingIds = array_values(array_filter($incomingIds));   // remove zeros

    if (count($incomingIds) !== count($staffRatings)) {
        sendJson(['error' => 'Each staff_rating must have a valid integer staff_id'], 422);
    }

    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id FROM staff
        WHERE  id IN ($placeholders)
        AND    form_code  = ?
        AND    is_active  = 1
    ");
    $stmt->execute([...$incomingIds, $formCode]);
    $validIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    $invalidIds = array_diff($incomingIds, $validIds);
    if (!empty($invalidIds)) {
        sendJson(['error' => 'Invalid or inactive staff IDs for this form: ' . implode(', ', $invalidIds)], 422);
    }

    // ── Validate each rating block ────────────────────────────────────────────
    foreach ($staffRatings as $i => $sr) {
        foreach (['rating_responsiveness','rating_resolution','rating_professionalism',
                  'rating_knowledge','rating_overall'] as $field) {
            $v = intval($sr[$field] ?? 0);
            if ($v < 1 || $v > 5) {
                sendJson(['error' => "staff_ratings[$i].$field must be between 1 and 5"], 422);
            }
        }
        if (trim($sr['what_did_well'] ?? '') === '') {
            sendJson(['error' => "staff_ratings[$i].what_did_well is required"], 422);
        }
    }

    // ── Generate reg_no: JSW-YYYYMMDD-NNNNNN ─────────────────────────────────
    $regNo = generateRegNo($pdo, $formCode);

    // ── Persist in a transaction ──────────────────────────────────────────────
    $pdo->beginTransaction();
    try {
        // 1. Insert submission
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $stmt = $pdo->prepare("
            INSERT INTO submissions (reg_no, form_code, vessel, seafarer_rank, ip_hash)
            VALUES (:reg_no, :form_code, :vessel, :rank, :ip_hash)
        ");
        $stmt->execute([
            ':reg_no'    => $regNo,
            ':form_code' => $formCode,
            ':vessel'    => $vessel,
            ':rank'      => $seafarerRank,
            ':ip_hash'   => $ipHash,
        ]);
        $submissionId = (int) $pdo->lastInsertId();

        // 2. Insert one response row per selected staff member (Step 2 multi-select)
        $stmtResp = $pdo->prepare("
            INSERT INTO responses
              (submission_id, staff_id,
               rating_responsiveness, rating_resolution,
               rating_professionalism, rating_knowledge, rating_overall,
               what_did_well, areas_to_improve, specific_incident, other_feedback)
            VALUES
              (:sub_id, :staff_id,
               :r1, :r2, :r3, :r4, :r5,
               :well, :improve, :incident, :other)
        ");

        foreach ($staffRatings as $sr) {
            $stmtResp->execute([
                ':sub_id'   => $submissionId,
                ':staff_id' => intval($sr['staff_id']),
                ':r1'       => intval($sr['rating_responsiveness']),
                ':r2'       => intval($sr['rating_resolution']),
                ':r3'       => intval($sr['rating_professionalism']),
                ':r4'       => intval($sr['rating_knowledge']),
                ':r5'       => intval($sr['rating_overall']),
                ':well'     => trim($sr['what_did_well']),
                ':improve'  => trim($sr['areas_to_improve']  ?? ''),
                ':incident' => trim($sr['specific_incident'] ?? ''),
                ':other'    => trim($sr['other_feedback']    ?? ''),
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    sendJson([
        'success'           => true,
        'reg_no'            => $regNo,
        'rated_staff_count' => count($staffRatings),
    ], 201);
}

// ── GET /api/survey/responses  or  /responses/{reg_no} ───────────────────────
/**
 * Optional query params (list mode):
 *   ?form_code=JSW
 *   ?vessel=JSW+MIHIRGAD
 *   ?staff_id=3
 *   ?from=2024-01-01&to=2024-12-31
 *   ?page=1&per_page=20
 *
 * ⚠️ Add proper authentication before exposing this in production.
 */
function handleGetResponses(?string $regNo): void
{
    $pdo = getPDO();

    if ($regNo !== null) {
        // Single submission
        $stmt = $pdo->prepare("
            SELECT s.reg_no, s.form_code, f.form_name,
                   s.vessel, s.seafarer_rank, s.submitted_at,
                   st.staff_key, st.full_name AS staff_name, st.role AS staff_role,
                   r.rating_responsiveness, r.rating_resolution,
                   r.rating_professionalism, r.rating_knowledge, r.rating_overall,
                   ROUND((r.rating_responsiveness + r.rating_resolution +
                          r.rating_professionalism + r.rating_knowledge +
                          r.rating_overall) / 5, 2) AS avg_score,
                   r.what_did_well, r.areas_to_improve, r.specific_incident, r.other_feedback
            FROM   responses   r
            JOIN   submissions s  ON s.id         = r.submission_id
            JOIN   staff       st ON st.id        = r.staff_id
            JOIN   forms       f  ON f.form_code  = s.form_code
            WHERE  s.reg_no = :reg_no
            ORDER  BY st.sort_order, st.full_name
        ");
        $stmt->execute([':reg_no' => $regNo]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) sendJson(['error' => 'Submission not found'], 404);
        sendJson(['submission' => $rows]);
        return;
    }

    // ── Build filtered list ───────────────────────────────────────────────────
    $where  = ['1=1'];
    $params = [];

    if (!empty($_GET['form_code'])) {
        $where[]             = 's.form_code = :form_code';
        $params[':form_code'] = $_GET['form_code'];
    }
    if (!empty($_GET['vessel'])) {
        $where[]           = 's.vessel = :vessel';
        $params[':vessel'] = $_GET['vessel'];
    }
    if (!empty($_GET['staff_id'])) {
        $where[]            = 'r.staff_id = :staff_id';
        $params[':staff_id'] = intval($_GET['staff_id']);
    }
    if (!empty($_GET['from'])) {
        $where[]         = 's.submitted_at >= :from';
        $params[':from'] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[]       = 's.submitted_at <= :to';
        $params[':to'] = $_GET['to'] . ' 23:59:59';
    }

    $page    = max(1, intval($_GET['page']     ?? 1));
    $perPage = max(1, min(100, intval($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;
    $whereClause = implode(' AND ', $where);

    // Total count
    $cStmt = $pdo->prepare("
        SELECT COUNT(*) FROM responses r
        JOIN submissions s ON s.id = r.submission_id
        WHERE $whereClause
    ");
    $cStmt->execute($params);
    $total = (int) $cStmt->fetchColumn();

    // Paginated rows
    $stmt = $pdo->prepare("
        SELECT s.reg_no, s.form_code, f.form_name,
               s.vessel, s.seafarer_rank, s.submitted_at,
               st.id AS staff_id, st.staff_key, st.full_name AS staff_name, st.role AS staff_role,
               r.rating_responsiveness, r.rating_resolution,
               r.rating_professionalism, r.rating_knowledge, r.rating_overall,
               ROUND((r.rating_responsiveness + r.rating_resolution +
                      r.rating_professionalism + r.rating_knowledge +
                      r.rating_overall) / 5, 2) AS avg_score,
               r.what_did_well, r.areas_to_improve, r.specific_incident, r.other_feedback
        FROM   responses   r
        JOIN   submissions s  ON s.id        = r.submission_id
        JOIN   staff       st ON st.id       = r.staff_id
        JOIN   forms       f  ON f.form_code = s.form_code
        WHERE  $whereClause
        ORDER  BY s.submitted_at DESC
        LIMIT  :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    sendJson([
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'data'     => $stmt->fetchAll(),
    ]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Generates: JSW-20240617-000001
 * Sequence resets daily per form_code.
 */
function generateRegNo(PDO $pdo, string $formCode): string
{
    $date = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM submissions
        WHERE form_code = ? AND DATE(submitted_at) = CURDATE()
    ");
    $stmt->execute([$formCode]);
    $todayCount = (int) $stmt->fetchColumn();
    $seq = str_pad((string)($todayCount + 1), 6, '0', STR_PAD_LEFT);
    return $formCode . '-' . $date . '-' . $seq;
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') sendJson(['error' => 'Empty request body'], 400);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJson(['error' => 'Invalid JSON: ' . json_last_error_msg()], 400);
    }
    return $data;
}

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
