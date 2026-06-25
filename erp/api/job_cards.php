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

try {
    $action = (string)($_REQUEST['action'] ?? '');

    if ($action === '') {
        apiResponse(false, 'Action is required.');
    }

    if (in_array($action, ['save_record', 'create', 'update', 'delete_record', 'delete'], true)) {
        apiCsrf();
    }

    if ($action === 'list') {
        apiResponse(true, 'Job cards loaded successfully.', ['data' => apiJobCardList($conn)]);
    }

    if ($action === 'view') {
        $id = jc_int($_REQUEST['id'] ?? 0);
        $row = apiJobCardRow($conn, $id);
        if (!$row) {
            apiResponse(false, 'Job card not found.');
        }
        apiResponse(true, 'Job card loaded successfully.', ['data' => $row]);
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

        apiResponse(true, $msg, ['id' => $jobId]);
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
