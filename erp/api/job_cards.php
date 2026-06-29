<?php
/**
 * api/job_cards.php
 * Action-based API for Job Cards module.
 * Backend processing moved from job_cards.php without changing DB schema/business flow.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'job_cards.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) { function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (empty($_SESSION['job_cards_csrf'])) $_SESSION['job_cards_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['job_cards_csrf'];
$message = ''; $messageType = 'success'; $toastTitle = 'Info';

function jc_table_exists(mysqli $conn, string $table): bool { try { $t=$conn->real_escape_string($table); $r=$conn->query("SHOW TABLES LIKE '{$t}'"); $ok=$r&&$r->num_rows>0; if($r)$r->free(); return $ok; } catch(Throwable $e){ return false; } }
function jc_col(mysqli $conn, string $table, string $col): bool { static $c=[]; $k="$table.$col"; if(isset($c[$k])) return $c[$k]; try { $t=$conn->real_escape_string($table); $cc=$conn->real_escape_string($col); $r=$conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$cc}'"); $ok=$r&&$r->num_rows>0; if($r)$r->free(); return $c[$k]=$ok; } catch(Throwable $e){ return $c[$k]=false; } }
function jc_post(string $k,string $d=''): string { return trim((string)($_POST[$k]??$d)); }
function jc_int($v): int { return (int)filter_var($v,FILTER_SANITIZE_NUMBER_INT); }
function jc_float($v): float { return (float)str_replace(',','',(string)$v); }
function jc_redirect(string $q=''): void { header('Location: job_cards.php'.($q!==''?'?'.$q:'')); exit; }
function jc_csrf(): void { if(empty($_POST['csrf_token'])||empty($_SESSION['job_cards_csrf'])||!hash_equals($_SESSION['job_cards_csrf'],(string)$_POST['csrf_token'])){ http_response_code(400); die('Invalid CSRF token.'); } }
function jc_next_no(mysqli $conn): string { $col=jc_col($conn,'job_cards','job_card_no')?'job_card_no':(jc_col($conn,'job_cards','job_no')?'job_no':'id'); $p='SC-JOB-'.date('ymd').'-'; try{ if($col==='id') return $p.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT); $like=$p.'%'; $st=$conn->prepare("SELECT COUNT(*) total FROM job_cards WHERE {$col} LIKE ?"); $st->bind_param('s',$like); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return $p.str_pad((string)(((int)($row['total']??0))+1),4,'0',STR_PAD_LEFT); } catch(Throwable $e){ return $p.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT); } }
function jc_bind_type($v): string { return is_int($v)?'i':(is_float($v)?'d':'s'); }
function jc_insert(mysqli $conn,string $table,array $data): int { $f=[]; foreach($data as $k=>$v){ if(jc_col($conn,$table,$k)) $f[$k]=$v; } if(!$f) throw new RuntimeException("No matching columns found for {$table}."); $cols=array_keys($f); $types=''; $vals=[]; foreach($f as $v){ $types.=jc_bind_type($v); $vals[]=$v; } $sql="INSERT INTO {$table} (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")"; $st=$conn->prepare($sql); $st->bind_param($types,...$vals); $st->execute(); $id=(int)$st->insert_id; $st->close(); return $id; }
function jc_update(mysqli $conn,string $table,array $data,int $id): void { $f=[]; foreach($data as $k=>$v){ if(jc_col($conn,$table,$k)) $f[$k]=$v; } if(!$f) throw new RuntimeException("No matching columns found for {$table}."); $sets=[]; $types=''; $vals=[]; foreach($f as $k=>$v){ $sets[]="`{$k}`=?"; $types.=jc_bind_type($v); $vals[]=$v; } $types.='i'; $vals[]=$id; $sql="UPDATE {$table} SET ".implode(',',$sets)." WHERE id=?"; $st=$conn->prepare($sql); $st->bind_param($types,...$vals); $st->execute(); $st->close(); }
function jc_multicolor_id(mysqli $conn): ?int { try{ if(!jc_table_exists($conn,'printing_types')) return null; if(jc_col($conn,'printing_types','printing_key')){ foreach(['multicolor_offset_printing','multi_color_offset_printing','multicolour_offset_printing','multicolor_offset'] as $key){ $st=$conn->prepare("SELECT id FROM printing_types WHERE printing_key=? AND is_active=1 LIMIT 1"); $st->bind_param('s',$key); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if($r) return (int)$r['id']; } } $o='%offset%'; $m1='%multicolor%'; $m2='%multi color%'; $m3='%multi-color%'; $m4='%multicolour%'; $st=$conn->prepare("SELECT id FROM printing_types WHERE is_active=1 AND LOWER(printing_name) LIKE ? AND (LOWER(printing_name) LIKE ? OR LOWER(printing_name) LIKE ? OR LOWER(printing_name) LIKE ? OR LOWER(printing_name) LIKE ?) ORDER BY sort_order ASC,id ASC LIMIT 1"); $st->bind_param('sssss',$o,$m1,$m2,$m3,$m4); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?(int)$r['id']:null; } catch(Throwable $e){ return null; } }
function jc_is_screen(mysqli $conn, ?int $pt): bool { if(!$pt||!jc_table_exists($conn,'printing_types')) return false; try{ $st=$conn->prepare("SELECT printing_name,printing_key FROM printing_types WHERE id=? LIMIT 1"); $st->bind_param('i',$pt); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r && (str_contains(strtolower((string)$r['printing_name']),'screen') || str_contains(strtolower((string)($r['printing_key']??'')),'screen')); } catch(Throwable $e){ return false; } }
function jc_status_id(mysqli $conn): ?int { try{ if(!jc_table_exists($conn,'job_card_statuses')) return null; if(jc_col($conn,'job_card_statuses','status_key')){ $k='in_progress'; $st=$conn->prepare("SELECT id FROM job_card_statuses WHERE status_key=? LIMIT 1"); $st->bind_param('s',$k); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?(int)$r['id']:null; } return null; } catch(Throwable $e){ return null; } }
function jc_first_step(mysqli $conn,string $orderType): ?int { try{ if(!jc_table_exists($conn,'workflow_steps')||!jc_col($conn,'workflow_steps','order_type')) return null; $pref=$orderType==='customized'?'designing':'proofing'; $st=$conn->prepare("SELECT id FROM workflow_steps WHERE order_type=? AND step_key=? AND is_active=1 ORDER BY sort_order ASC LIMIT 1"); $st->bind_param('ss',$orderType,$pref); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if($r) return (int)$r['id']; $st=$conn->prepare("SELECT id FROM workflow_steps WHERE order_type=? AND is_active=1 ORDER BY sort_order ASC LIMIT 1"); $st->bind_param('s',$orderType); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?(int)$r['id']:null; } catch(Throwable $e){ return null; } }
function jc_product_name(mysqli $conn, ?int $id, string $manual): string { if(trim($manual)!=='') return trim($manual); if(!$id||!jc_table_exists($conn,'products')) return 'Cards'; try{ $st=$conn->prepare("SELECT product_name FROM products WHERE id=? LIMIT 1"); $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?(string)$r['product_name']:'Cards'; }catch(Throwable $e){ return 'Cards'; } }

function apiResponse(bool $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function apiCsrf(): void
{
    if (
        empty($_REQUEST['csrf_token']) ||
        empty($_SESSION['job_cards_csrf']) ||
        !hash_equals($_SESSION['job_cards_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        apiResponse(false, 'Invalid CSRF token.');
    }
}

function apiProducts(mysqli $conn): array
{
    $rows = [];
    try {
        if (jc_table_exists($conn, 'products')) {
            $res = $conn->query("SELECT id,product_name,default_price FROM products WHERE is_active=1 ORDER BY product_name ASC");
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
    } catch (Throwable $e) {}
    return $rows;
}

function apiJobCardRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !jc_table_exists($conn, 'job_cards')) {
        return null;
    }

    try {
        $jobNoExpr = jc_col($conn,'job_cards','job_card_no') ? 'jc.job_card_no' : (jc_col($conn,'job_cards','job_no') ? 'jc.job_no' : "CONCAT('JOB-',jc.id)");
        $statusJoin = jc_table_exists($conn,'job_card_statuses') && jc_col($conn,'job_cards','job_card_status_id') ? 'LEFT JOIN job_card_statuses jcs ON jcs.id=jc.job_card_status_id' : '';
        $workflowJoin = jc_table_exists($conn,'workflow_steps') && jc_col($conn,'job_cards','current_workflow_step_id') ? 'LEFT JOIN workflow_steps ws ON ws.id=jc.current_workflow_step_id' : '';
        $printingJoin = jc_table_exists($conn,'printing_types') && jc_col($conn,'job_cards','printing_type_id') ? 'LEFT JOIN printing_types pt ON pt.id=jc.printing_type_id' : '';
        $subJoin = jc_table_exists($conn,'printing_sub_types') && jc_col($conn,'job_cards','printing_sub_type_id') ? 'LEFT JOIN printing_sub_types pst ON pst.id=jc.printing_sub_type_id' : '';
        $itemJoin = jc_table_exists($conn,'job_card_items') ? 'LEFT JOIN job_card_items jci ON jci.job_card_id=jc.id' : '';
        $statusExpr = "COALESCE(" . (jc_table_exists($conn,'job_card_statuses') && jc_col($conn,'job_card_statuses','status_name') ? 'jcs.status_name,' : '') . (jc_col($conn,'job_cards','status') ? 'jc.status,' : '') . "'Active')";
        $itemSelect = jc_table_exists($conn,'job_card_items') ? 'jci.item_name,jci.qty,jci.rate,jci.amount,jci.size_text,jci.gsm_thickness,jci.lamination_required,jci.lamination_type,jci.printing_side,jci.screening_type,jci.finishing_required' : 'NULL item_name,NULL qty,NULL rate,NULL amount,NULL size_text,NULL gsm_thickness,NULL lamination_required,NULL lamination_type,NULL printing_side,NULL screening_type,NULL finishing_required';
        $sql = "SELECT jc.*,{$jobNoExpr} display_job_no,{$statusExpr} display_status," . (jc_table_exists($conn,'workflow_steps') ? 'ws.step_name' : 'NULL') . " current_step_name," . (jc_table_exists($conn,'printing_types') ? 'pt.printing_name' : 'NULL') . " printing_name," . (jc_table_exists($conn,'printing_sub_types') ? 'pst.sub_type_name' : 'NULL') . " sub_type_name,{$itemSelect} FROM job_cards jc {$statusJoin} {$workflowJoin} {$printingJoin} {$subJoin} {$itemJoin} WHERE jc.id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function apiJobCardList(mysqli $conn): array
{
    $rows = [];
    if (!jc_table_exists($conn, 'job_cards')) {
        return $rows;
    }

    try {
        $jobNoExpr = jc_col($conn,'job_cards','job_card_no') ? 'jc.job_card_no' : (jc_col($conn,'job_cards','job_no') ? 'jc.job_no' : "CONCAT('JOB-',jc.id)");
        $statusJoin = jc_table_exists($conn,'job_card_statuses') && jc_col($conn,'job_cards','job_card_status_id') ? 'LEFT JOIN job_card_statuses jcs ON jcs.id=jc.job_card_status_id' : '';
        $workflowJoin = jc_table_exists($conn,'workflow_steps') && jc_col($conn,'job_cards','current_workflow_step_id') ? 'LEFT JOIN workflow_steps ws ON ws.id=jc.current_workflow_step_id' : '';
        $printingJoin = jc_table_exists($conn,'printing_types') && jc_col($conn,'job_cards','printing_type_id') ? 'LEFT JOIN printing_types pt ON pt.id=jc.printing_type_id' : '';
        $subJoin = jc_table_exists($conn,'printing_sub_types') && jc_col($conn,'job_cards','printing_sub_type_id') ? 'LEFT JOIN printing_sub_types pst ON pst.id=jc.printing_sub_type_id' : '';
        $itemJoin = jc_table_exists($conn,'job_card_items') ? 'LEFT JOIN job_card_items jci ON jci.job_card_id=jc.id' : '';
        $statusExpr = "COALESCE(" . (jc_table_exists($conn,'job_card_statuses') && jc_col($conn,'job_card_statuses','status_name') ? 'jcs.status_name,' : '') . (jc_col($conn,'job_cards','status') ? 'jc.status,' : '') . "'Active')";
        $itemSelect = jc_table_exists($conn,'job_card_items') ? 'jci.item_name,jci.qty,jci.rate,jci.amount,jci.size_text,jci.gsm_thickness,jci.lamination_required,jci.lamination_type,jci.printing_side,jci.screening_type,jci.finishing_required' : 'NULL item_name,NULL qty,NULL rate,NULL amount,NULL size_text,NULL gsm_thickness,NULL lamination_required,NULL lamination_type,NULL printing_side,NULL screening_type,NULL finishing_required';
        $sql = "SELECT jc.*,{$jobNoExpr} display_job_no,{$statusExpr} display_status," . (jc_table_exists($conn,'workflow_steps') ? 'ws.step_name' : 'NULL') . " current_step_name," . (jc_table_exists($conn,'printing_types') ? 'pt.printing_name' : 'NULL') . " printing_name," . (jc_table_exists($conn,'printing_sub_types') ? 'pst.sub_type_name' : 'NULL') . " sub_type_name,{$itemSelect} FROM job_cards jc {$statusJoin} {$workflowJoin} {$printingJoin} {$subJoin} {$itemJoin} ORDER BY jc.id DESC LIMIT 300";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {}

    return $rows;
}


function jc_current_role_ids(mysqli $conn): array
{
    $ids = [];

    foreach (['role_id', 'current_role_id'] as $key) {
        if (!empty($_SESSION[$key])) {
            $ids[] = (int)$_SESSION[$key];
        }
    }

    foreach (['role_ids', 'user_role_ids'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $id) {
                $ids[] = (int)$id;
            }
        }
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['id'])) {
                $ids[] = (int)$role['id'];
            } elseif (is_numeric($role)) {
                $ids[] = (int)$role;
            }
        }
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && jc_table_exists($conn, 'user_roles')) {
        try {
            $stmt = $conn->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['role_id'];
            }
            $stmt->close();
        } catch (Throwable $e) {}
    }

    return array_values(array_unique(array_filter($ids)));
}

function jc_current_role_keys(mysqli $conn): array
{
    $keys = [];

    foreach (['role_key', 'current_role_key'] as $key) {
        if (!empty($_SESSION[$key])) {
            $keys[] = strtolower((string)$_SESSION[$key]);
        }
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['role_key'])) {
                $keys[] = strtolower((string)$role['role_key']);
            } elseif (is_string($role)) {
                $keys[] = strtolower($role);
            }
        }
    }

    $roleIds = jc_current_role_ids($conn);
    if ($roleIds && jc_table_exists($conn, 'roles') && jc_col($conn, 'roles', 'role_key')) {
        try {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $stmt = $conn->prepare("SELECT role_key FROM roles WHERE id IN ({$placeholders})");
            $stmt->bind_param($types, ...$roleIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $keys[] = strtolower((string)$row['role_key']);
            }
            $stmt->close();
        } catch (Throwable $e) {}
    }

    return array_values(array_unique(array_filter($keys)));
}

function jc_is_admin_user(mysqli $conn): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) return true;
    if (!empty($_SESSION['is_super_admin'])) return true;

    $roleKeys = jc_current_role_keys($conn);
    foreach ($roleKeys as $key) {
        if (in_array($key, ['super_admin', 'admin', 'business_admin'], true)) {
            return true;
        }
    }

    return false;
}

function jc_can_update_tracking(mysqli $conn, array $trackingRow): bool
{
    if (jc_is_admin_user($conn)) return true;

    $responsibleRoleId = (int)($trackingRow['responsible_role_id'] ?? 0);
    if ($responsibleRoleId <= 0) return false;

    return in_array($responsibleRoleId, jc_current_role_ids($conn), true);
}

function jc_status_id_by_key(mysqli $conn, string $key): ?int
{
    if (!jc_table_exists($conn, 'job_card_statuses')) return null;

    try {
        if (jc_col($conn, 'job_card_statuses', 'status_key')) {
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
        } elseif (jc_col($conn, 'job_card_statuses', 'status_name')) {
            $name = ucwords(str_replace('_', ' ', $key));
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE LOWER(status_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $name);
        } else {
            return null;
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function jc_job_status_key_by_id(mysqli $conn, ?int $statusId): string
{
    if (!$statusId || !jc_table_exists($conn, 'job_card_statuses')) return 'in_progress';

    try {
        if (jc_col($conn, 'job_card_statuses', 'status_key')) {
            $stmt = $conn->prepare("SELECT status_key, status_name FROM job_card_statuses WHERE id = ? LIMIT 1");
        } else {
            $stmt = $conn->prepare("SELECT NULL AS status_key, status_name FROM job_card_statuses WHERE id = ? LIMIT 1");
        }

        $stmt->bind_param('i', $statusId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $key = strtolower(trim((string)($row['status_key'] ?? '')));
        if ($key !== '') return $key;

        $name = strtolower(trim((string)($row['status_name'] ?? '')));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        return trim($name, '_') ?: 'in_progress';
    } catch (Throwable $e) {
        return 'in_progress';
    }
}

function jc_tracking_history_insert(mysqli $conn, int $trackingId, int $jobCardId, int $workflowStepId, string $oldStatus, string $newStatus, string $remarks, int $userId): void
{
    if (!jc_table_exists($conn, 'job_tracking_history')) return;

    try {
        $history = [
            'job_tracking_id' => $trackingId,
            'job_card_id' => $jobCardId,
            'workflow_step_id' => $workflowStepId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action_remarks' => $remarks,
            'changed_by' => $userId ?: null,
            'changed_at' => date('Y-m-d H:i:s')
        ];

        if (jc_col($conn, 'job_tracking_history', 'old_data')) {
            $history['old_data'] = json_encode(['status' => $oldStatus], JSON_UNESCAPED_UNICODE);
        }

        if (jc_col($conn, 'job_tracking_history', 'new_data')) {
            $history['new_data'] = json_encode(['status' => $newStatus], JSON_UNESCAPED_UNICODE);
        }

        jc_insert($conn, 'job_tracking_history', $history);
    } catch (Throwable $e) {}
}



function jc_default_delay_reason_id(mysqli $conn): ?int
{
    if (!jc_table_exists($conn, 'delay_reasons')) return null;

    try {
        $key = 'other';
        if (jc_col($conn, 'delay_reasons', 'reason_key')) {
            $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE reason_key = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['id'];
        }

        $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function jc_next_expected_date($plannedDate): ?string
{
    if (empty($plannedDate)) return null;

    try {
        $dt = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $dt->modify('+1 day');
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function jc_auto_mark_overdue_tracking(mysqli $conn, int $jobCardId = 0): array
{
    $summary = ['checked' => 0, 'updated' => 0];

    if (!jc_table_exists($conn, 'job_tracking')) {
        return $summary;
    }

    $today = date('Y-m-d');
    $defaultReasonId = jc_default_delay_reason_id($conn);

    try {
        $where = "WHERE jt.status NOT IN ('completed','cancelled','skipped')
                    AND jt.planned_completion_date IS NOT NULL
                    AND jt.planned_completion_date < ?";
        $types = 's';
        $params = [$today];

        if ($jobCardId > 0) {
            $where .= " AND jt.job_card_id = ?";
            $types .= 'i';
            $params[] = $jobCardId;
        }

        $stmt = $conn->prepare("SELECT jt.* FROM job_tracking jt {$where}");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $summary['checked']++;
            $trackingId = (int)$row['id'];
            $plannedDate = (string)($row['planned_completion_date'] ?? '');
            $nextExpectedDate = !empty($row['revised_completion_date']) ? (string)$row['revised_completion_date'] : jc_next_expected_date($plannedDate);
            $delayDays = jc_delay_days_from_planned($plannedDate);
            $oldStatus = (string)($row['status'] ?? 'pending');

            $data = [];
            if (jc_col($conn, 'job_tracking', 'status') && $oldStatus !== 'delayed') $data['status'] = 'delayed';
            if (jc_col($conn, 'job_tracking', 'is_delayed')) $data['is_delayed'] = 1;
            if (jc_col($conn, 'job_tracking', 'delay_started_at') && empty($row['delay_started_at'])) $data['delay_started_at'] = date('Y-m-d H:i:s');
            if (jc_col($conn, 'job_tracking', 'delay_days')) $data['delay_days'] = max(1, $delayDays);
            if (jc_col($conn, 'job_tracking', 'revised_completion_date') && empty($row['revised_completion_date'])) $data['revised_completion_date'] = $nextExpectedDate;
            if (jc_col($conn, 'job_tracking', 'delay_reason_id') && empty($row['delay_reason_id']) && $defaultReasonId) $data['delay_reason_id'] = $defaultReasonId;
            if (jc_col($conn, 'job_tracking', 'delay_remarks') && trim((string)($row['delay_remarks'] ?? '')) === '') $data['delay_remarks'] = 'Auto marked delayed because planned date was missed.';
            if (jc_col($conn, 'job_tracking', 'updated_at')) $data['updated_at'] = date('Y-m-d H:i:s');

            if ($data) {
                jc_update($conn, 'job_tracking', $data, $trackingId);
                $summary['updated']++;

                jc_tracking_history_insert(
                    $conn,
                    $trackingId,
                    (int)$row['job_card_id'],
                    (int)$row['workflow_step_id'],
                    $oldStatus,
                    $data['status'] ?? $oldStatus,
                    'Auto marked delayed. Original planned date: ' . $plannedDate . '. Next expected date: ' . ($nextExpectedDate ?: '-') . '.',
                    0
                );
            }
        }
        $stmt->close();

        if ($jobCardId > 0) {
            jc_update_job_card_progress($conn, $jobCardId);
        }
    } catch (Throwable $e) {}

    return $summary;
}


function jc_delay_days_from_planned($plannedDate): int
{
    if (empty($plannedDate)) return 0;

    try {
        $planned = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $today = new DateTime(date('Y-m-d'));

        if ($today <= $planned) return 0;

        return (int)$planned->diff($today)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function jc_tracking_needs_delay_reason(array $trackingRow, string $newStatus): bool
{
    if (!in_array($newStatus, ['in_progress', 'completed', 'delayed'], true)) {
        return false;
    }

    $delayDays = jc_delay_days_from_planned($trackingRow['planned_completion_date'] ?? null);
    if ($delayDays <= 0) return false;

    $hasReason = !empty($trackingRow['delay_reason_id']) || trim((string)($trackingRow['delay_remarks'] ?? '')) !== '';

    return !$hasReason;
}

function jc_apply_delay_data(mysqli $conn, array &$data, array $trackingRow, string $newStatus, ?int $delayReasonId, string $delayRemarks): void
{
    $delayDays = jc_delay_days_from_planned($trackingRow['planned_completion_date'] ?? null);
    $isDelayed = $delayDays > 0 && in_array($newStatus, ['in_progress', 'completed', 'delayed'], true);

    if (!$isDelayed) return;

    if (!$delayReasonId || trim($delayRemarks) === '') {
        throw new RuntimeException('Delay reason and delay remarks are required before updating this delayed job status.');
    }

    if (jc_col($conn, 'job_tracking', 'is_delayed')) $data['is_delayed'] = 1;
    if (jc_col($conn, 'job_tracking', 'delay_started_at') && empty($trackingRow['delay_started_at'])) $data['delay_started_at'] = date('Y-m-d H:i:s');
    if (jc_col($conn, 'job_tracking', 'delay_days')) $data['delay_days'] = $delayDays;
    if (jc_col($conn, 'job_tracking', 'revised_completion_date') && empty($trackingRow['revised_completion_date'])) $data['revised_completion_date'] = jc_next_expected_date($trackingRow['planned_completion_date'] ?? null);
    if (jc_col($conn, 'job_tracking', 'delay_reason_id')) $data['delay_reason_id'] = $delayReasonId;
    if (jc_col($conn, 'job_tracking', 'delay_remarks')) $data['delay_remarks'] = $delayRemarks;
}


function jc_sync_tracking_from_job_card(mysqli $conn, int $jobCardId, ?int $selectedStepId, ?int $selectedStatusId, string $remarks = '', ?int $delayReasonId = null, string $delayRemarks = ''): array
{
    if ($jobCardId <= 0 || !$selectedStepId || !jc_table_exists($conn, 'job_tracking')) {
        return jc_update_job_card_progress($conn, $jobCardId);
    }

    $jobStatusKey = jc_job_status_key_by_id($conn, $selectedStatusId);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $rows = [];
    try {
        $join = jc_table_exists($conn, 'workflow_steps') ? 'LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id' : '';
        $sort = jc_table_exists($conn, 'workflow_steps') ? 'ORDER BY ws.sort_order ASC, jt.id ASC' : 'ORDER BY jt.id ASC';
        $stmt = $conn->prepare("SELECT jt.*, " . (jc_table_exists($conn, 'workflow_steps') ? 'ws.sort_order' : 'jt.id AS sort_order') . " FROM job_tracking jt {$join} WHERE jt.job_card_id = ? {$sort}");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {}

    if (!$rows) {
        return jc_update_job_card_progress($conn, $jobCardId);
    }

    $selectedIndex = null;
    foreach ($rows as $index => $row) {
        if ((int)($row['workflow_step_id'] ?? 0) === (int)$selectedStepId) {
            $selectedIndex = $index;
            break;
        }
    }

    if ($selectedIndex === null) {
        return jc_update_job_card_progress($conn, $jobCardId);
    }

    $allCompleted = in_array($jobStatusKey, ['completed', 'complete', 'closed', 'delivered'], true);

    foreach ($rows as $index => $row) {
        $trackingId = (int)($row['id'] ?? 0);
        $workflowStepId = (int)($row['workflow_step_id'] ?? 0);
        $oldStatus = (string)($row['status'] ?? 'pending');

        if ($allCompleted || $index < $selectedIndex) {
            $newStatus = 'completed';
        } elseif ($index === $selectedIndex) {
            $newStatus = 'in_progress';
        } else {
            $newStatus = 'pending';
        }

        $data = ['status' => $newStatus];

        if ($newStatus === 'completed') {
            if (jc_col($conn, 'job_tracking', 'actual_start_at') && empty($row['actual_start_at'])) $data['actual_start_at'] = $now;
            if (jc_col($conn, 'job_tracking', 'actual_completed_at') && empty($row['actual_completed_at'])) $data['actual_completed_at'] = $now;
            if (jc_col($conn, 'job_tracking', 'completed_by') && empty($row['completed_by'])) $data['completed_by'] = $userId ?: null;
        } elseif ($newStatus === 'in_progress') {
            if (jc_col($conn, 'job_tracking', 'actual_start_at') && empty($row['actual_start_at'])) $data['actual_start_at'] = $now;
            if (jc_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
            if (jc_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
        } else {
            if (jc_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
            if (jc_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
        }

        jc_apply_delay_data($conn, $data, $row, $newStatus, $delayReasonId, $delayRemarks);

        if (jc_col($conn, 'job_tracking', 'updated_at')) $data['updated_at'] = $now;

        jc_update($conn, 'job_tracking', $data, $trackingId);

        if ($oldStatus !== $newStatus) {
            jc_tracking_history_insert(
                $conn,
                $trackingId,
                $jobCardId,
                $workflowStepId,
                $oldStatus,
                $newStatus,
                ($remarks !== '' ? $remarks : 'Status synced from Job Card update.') . ($delayRemarks !== '' ? ' Delay: ' . $delayRemarks : ''),
                $userId
            );
        }
    }

    return jc_update_job_card_progress($conn, $jobCardId);
}



function jc_update_job_card_progress(mysqli $conn, int $jobCardId): array
{
    $total = 0;
    $completed = 0;
    $inProgress = 0;
    $pending = 0;
    $currentStepId = null;
    $allCompleted = false;

    if ($jobCardId <= 0 || !jc_table_exists($conn, 'job_tracking')) {
        return ['progress' => 0, 'current_step_id' => null, 'job_status_key' => 'in_progress'];
    }

    $rows = [];
    try {
        $join = jc_table_exists($conn, 'workflow_steps') ? 'LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id' : '';
        $sort = jc_table_exists($conn, 'workflow_steps') ? 'ORDER BY ws.sort_order ASC, jt.id ASC' : 'ORDER BY jt.id ASC';
        $stmt = $conn->prepare("SELECT jt.*, " . (jc_table_exists($conn, 'workflow_steps') ? 'ws.sort_order' : 'jt.id AS sort_order') . " FROM job_tracking jt {$join} WHERE jt.job_card_id = ? {$sort}");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {}

    $total = count($rows);

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'pending');

        if ($status === 'completed') {
            $completed++;
        } elseif ($status === 'in_progress') {
            $inProgress++;
        } else {
            $pending++;
        }

        if ($currentStepId === null && $status !== 'completed') {
            $currentStepId = (int)($row['workflow_step_id'] ?? 0);
        }
    }

    if ($total > 0 && $completed === $total) {
        $allCompleted = true;
        $last = end($rows);
        $currentStepId = (int)($last['workflow_step_id'] ?? 0);
    }

    $progress = $total > 0 ? (int)round(($completed / $total) * 100) : 0;
    $jobStatusKey = $allCompleted ? 'completed' : 'in_progress';
    $statusId = jc_status_id_by_key($conn, $jobStatusKey);

    $updates = [];
    if (jc_col($conn, 'job_cards', 'current_workflow_step_id')) $updates['current_workflow_step_id'] = $currentStepId ?: null;
    if ($statusId && jc_col($conn, 'job_cards', 'job_card_status_id')) $updates['job_card_status_id'] = $statusId;
    if (jc_col($conn, 'job_cards', 'updated_at')) $updates['updated_at'] = date('Y-m-d H:i:s');

    if ($updates && jc_table_exists($conn, 'job_cards')) {
        jc_update($conn, 'job_cards', $updates, $jobCardId);
    }

    return [
        'progress' => $progress,
        'total' => $total,
        'completed' => $completed,
        'in_progress' => $inProgress,
        'pending' => $pending,
        'current_step_id' => $currentStepId,
        'job_status_key' => $jobStatusKey
    ];
}

function jc_tracking_status_update(mysqli $conn, int $trackingId, string $newStatus, string $remarks = '', ?int $delayReasonId = null, string $delayRemarks = ''): array
{
    if ($trackingId <= 0) throw new RuntimeException('Invalid tracking record.');
    if (!in_array($newStatus, ['pending', 'in_progress', 'completed'], true)) {
        throw new RuntimeException('Invalid tracking status.');
    }
    if (!jc_table_exists($conn, 'job_tracking')) {
        throw new RuntimeException('job_tracking table is missing.');
    }

    $stmt = $conn->prepare("
        SELECT jt.*, ws.step_name, ws.step_key
        FROM job_tracking jt
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        WHERE jt.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $tracking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tracking) throw new RuntimeException('Tracking step not found.');

    if (!jc_can_update_tracking($conn, $tracking)) {
        throw new RuntimeException('You do not have role access to update this tracking step.');
    }

    $jobCardId = (int)($tracking['job_card_id'] ?? 0);
    $oldStatus = (string)($tracking['status'] ?? 'pending');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $data = ['status' => $newStatus];

    if ($newStatus === 'pending') {
        if (jc_col($conn, 'job_tracking', 'actual_start_at')) $data['actual_start_at'] = null;
        if (jc_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
        if (jc_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
    } elseif ($newStatus === 'in_progress') {
        if (jc_col($conn, 'job_tracking', 'actual_start_at') && empty($tracking['actual_start_at'])) $data['actual_start_at'] = $now;
        if (jc_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
        if (jc_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
    } elseif ($newStatus === 'completed') {
        if (jc_col($conn, 'job_tracking', 'actual_start_at') && empty($tracking['actual_start_at'])) $data['actual_start_at'] = $now;
        if (jc_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = $now;
        if (jc_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = $userId ?: null;
    }

    jc_apply_delay_data($conn, $data, $tracking, $newStatus, $delayReasonId, $delayRemarks);

    if (jc_col($conn, 'job_tracking', 'updated_at')) $data['updated_at'] = $now;

    $conn->begin_transaction();

    jc_update($conn, 'job_tracking', $data, $trackingId);
    jc_tracking_history_insert($conn, $trackingId, $jobCardId, (int)($tracking['workflow_step_id'] ?? 0), $oldStatus, $newStatus, ($remarks !== '' ? $remarks : 'Status updated from job progress page.') . ($delayRemarks !== '' ? ' Delay: ' . $delayRemarks : ''), $userId);


    $progress = jc_update_job_card_progress($conn, $jobCardId);

    $conn->commit();

    return [
        'tracking_id' => $trackingId,
        'job_card_id' => $jobCardId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'progress' => $progress
    ];
}


try {
    $action = (string)($_REQUEST['action'] ?? '');

    if ($action === '') {
        apiResponse(false, 'Action is required.');
    }

    if (in_array($action, ['save_record', 'create', 'update', 'delete_record', 'delete', 'update_tracking_status', 'auto_mark_delays'], true)) {
        apiCsrf();
    }


    if ($action === 'auto_mark_delays') {
        $jobCardId = jc_int($_POST['job_card_id'] ?? 0);
        $summary = jc_auto_mark_overdue_tracking($conn, $jobCardId);
        apiResponse(true, 'Delayed tracking stages checked successfully.', ['summary' => $summary]);
    }

    if ($action === 'list') {
        jc_auto_mark_overdue_tracking($conn);
        apiResponse(true, 'Job cards loaded successfully.', ['data' => apiJobCardList($conn)]);
    }

    if ($action === 'view') {
        $id = jc_int($_REQUEST['id'] ?? 0);
        jc_auto_mark_overdue_tracking($conn, $id);
        $row = apiJobCardRow($conn, $id);
        if (!$row) {
            apiResponse(false, 'Job card not found.');
        }
        apiResponse(true, 'Job card loaded successfully.', ['data' => $row]);
    }


    if ($action === 'update_tracking_status') {
        $trackingId = jc_int($_POST['tracking_id'] ?? 0);
        $newStatus = jc_post('status');
        $remarks = jc_post('remarks');
        $delayReasonId = jc_int($_POST['delay_reason_id'] ?? 0) ?: null;
        $delayRemarks = jc_post('delay_remarks');

        $result = jc_tracking_status_update($conn, $trackingId, $newStatus, $remarks, $delayReasonId, $delayRemarks);

        apiResponse(true, 'Job tracking status updated successfully.', $result);
    }

    if (in_array($action, ['save_record', 'create', 'update'], true)) {
        if (!jc_table_exists($conn,'job_cards')) throw new RuntimeException('job_cards table is missing.');

        $id = jc_int($_POST['id'] ?? 0);
        $orderType = jc_post('order_type','readymade');
        $jobNo = jc_post('job_card_no') ?: jc_next_no($conn);
        $customerName = jc_post('customer_name');
        $mobile = jc_post('mobile');
        $deliveryDate = jc_post('delivery_date') ?: null;
        $productId = jc_int($_POST['product_id'] ?? 0) ?: null;
        $productName = jc_product_name($conn,$productId,jc_post('product_name'));
        $printingTypeId = jc_int($_POST['printing_type_id'] ?? 0) ?: null;
        $printingSubTypeId = jc_int($_POST['printing_sub_type_id'] ?? 0) ?: null;
        $sizeText = jc_post('size_text');
        $gsm = jc_post('gsm_thickness');
        $lamReq = jc_int($_POST['lamination_required'] ?? 0) === 1 ? 1 : 0;
        $lamType = jc_post('lamination_type') ?: null;
        $side = jc_post('printing_side') ?: null;
        $screening = jc_post('screening_type') ?: null;
        $finish = jc_int($_POST['finishing_required'] ?? 0) === 1 ? 1 : 0;
        $final = jc_float($_POST['final_amount'] ?? 0);
        $advance = jc_float($_POST['advance_amount'] ?? 0);
        $balance = max(0,$final-$advance);
        $qty = jc_float($_POST['qty'] ?? 1);
        $rate = jc_float($_POST['rate'] ?? 0);
        $amount = $qty*$rate;
        $notes = jc_post('notes');
        $delayReasonId = jc_int($_POST['delay_reason_id'] ?? 0) ?: null;
        $delayRemarks = jc_post('delay_remarks');

        if(!in_array($orderType,['readymade','customized'],true)) throw new RuntimeException('Invalid order type.');
        if($customerName===''||$mobile==='') throw new RuntimeException('Customer name and mobile are required.');
        if($productName==='') throw new RuntimeException('Product / item name is required.');

        if($orderType==='customized'){
            $multi=jc_multicolor_id($conn); if(!$multi) throw new RuntimeException('Multicolor Offset Print is missing in Printing Types master.');
            $printingTypeId=$multi; $printingSubTypeId=null; $finish=0;
            if($sizeText==='') throw new RuntimeException('Size is required for customized order.');
            if($gsm==='') throw new RuntimeException('GSM Thickness is required for customized order.');
            if($lamReq===1 && !$lamType) throw new RuntimeException('Please select lamination type.');
            if(!$side) throw new RuntimeException('Please select Single Side or Double Side.');
            if(!$screening) throw new RuntimeException('Please select Regular Screening or Special Screening.');
        } else {
            if(!$printingTypeId) throw new RuntimeException('Please select printing type.');
            if(jc_is_screen($conn,$printingTypeId) && !$printingSubTypeId) throw new RuntimeException('Please select Screen Print sub-type.');
            $sizeText=''; $gsm=''; $lamReq=0; $lamType=null; $side=null; $screening=null;
        }

        $uid=(int)($_SESSION['user_id']??0);
        $statusId=jc_int($_POST['job_card_status_id']??0)?:jc_status_id($conn);
        $stepId=jc_int($_POST['current_workflow_step_id']??0)?:jc_first_step($conn,$orderType);

        $jobData=[
            'job_card_no'=>$jobNo,
            'job_no'=>$jobNo,
            'tracking_token'=>bin2hex(random_bytes(24)),
            'proforma_bill_id'=>jc_int($_POST['proforma_bill_id']??0)?:null,
            'quotation_id'=>jc_int($_POST['quotation_id']??0)?:null,
            'customer_id'=>jc_int($_POST['customer_id']??0)?:null,
            'order_type'=>$orderType,
            'customer_name'=>$customerName,
            'mobile'=>$mobile,
            'function_type_id'=>jc_int($_POST['function_type_id']??0)?:null,
            'product_id'=>$productId,
            'product_name'=>$productName,
            'printing_type_id'=>$printingTypeId,
            'printing_sub_type_id'=>$printingSubTypeId,
            'job_card_status_id'=>$statusId,
            'current_workflow_step_id'=>$stepId,
            'final_amount'=>$final,
            'advance_amount'=>$advance,
            'balance_amount'=>$balance,
            'delivery_date'=>$deliveryDate,
            'notes'=>$notes,
            'status'=>'Active',
            'created_by'=>$uid,
            'updated_at'=>date('Y-m-d H:i:s')
        ];

        if($id>0){
            jc_update($conn,'job_cards',$jobData,$id);
            $jobId=$id;
            if(jc_table_exists($conn,'job_card_items')){
                $st=$conn->prepare('DELETE FROM job_card_items WHERE job_card_id=?');
                $st->bind_param('i',$jobId);
                $st->execute();
                $st->close();
            }
            $msg='Job card updated successfully.';
        } else {
            $jobData['created_at']=date('Y-m-d H:i:s');
            $jobId=jc_insert($conn,'job_cards',$jobData);
            $msg='Job card saved successfully.';
        }

        if(jc_table_exists($conn,'job_card_items')) {
            jc_insert($conn,'job_card_items',[
                'job_card_id'=>$jobId,
                'product_id'=>$productId,
                'item_name'=>$productName,
                'description'=>$notes,
                'qty'=>$qty,
                'rate'=>$rate,
                'amount'=>$amount,
                'size_text'=>$sizeText,
                'gsm_thickness'=>$gsm,
                'lamination_required'=>$lamReq,
                'lamination_type'=>$lamType,
                'printing_side'=>$side,
                'screening_type'=>$screening,
                'finishing_required'=>$finish,
                'created_at'=>date('Y-m-d H:i:s')
            ]);
        }

        $progressSync = jc_sync_tracking_from_job_card($conn, $jobId, $stepId, $statusId, 'Status synced from Job Card page.', $delayReasonId, $delayRemarks);

        apiResponse(true, $msg, ['id' => $jobId, 'progress' => $progressSync]);
    }

    if (in_array($action, ['delete_record', 'delete'], true)) {
        $id = jc_int($_POST['id'] ?? 0);
        if($id<=0) throw new RuntimeException('Invalid record.');

        if(jc_col($conn,'job_cards','status')) {
            jc_update($conn,'job_cards',['status'=>'Inactive','updated_at'=>date('Y-m-d H:i:s')],$id);
        }

        apiResponse(true, 'Job card disabled successfully.', ['id' => $id]);
    }

    apiResponse(false, 'Invalid action.');
} catch (Throwable $e) {
    apiResponse(false, $e->getMessage());
}
