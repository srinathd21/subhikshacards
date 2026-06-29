<?php
/**
 * job_cards.php
 * Subhiksha Cards ERP - Job Cards requirement based page with Select2
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'job_cards.php');
if (session_status() === PHP_SESSION_NONE) session_start();
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

$products=[]; $printingTypes=[]; $printingSubTypes=[]; $statuses=[]; $workflowSteps=[]; $proformas=[]; $delayReasons=[]; $multiColorId=jc_multicolor_id($conn); $nextJobNo=jc_next_no($conn);
try{ if(jc_table_exists($conn,'products')){ $res=$conn->query("SELECT id,product_name,default_price FROM products WHERE is_active=1 ORDER BY product_name ASC"); while($r=$res->fetch_assoc()) $products[]=$r; $res->free(); } }catch(Throwable $e){}
try{ if(jc_table_exists($conn,'printing_types')){ $res=$conn->query("SELECT id,printing_name,COALESCE(printing_key,'') printing_key,LOWER(REPLACE(REPLACE(COALESCE(printing_key,printing_name),' ','_'),'-','_')) normalized_key FROM printing_types WHERE is_active=1 ORDER BY sort_order ASC,printing_name ASC"); while($r=$res->fetch_assoc()) $printingTypes[]=$r; $res->free(); } }catch(Throwable $e){}
try{ if(jc_table_exists($conn,'printing_sub_types')){ $res=$conn->query("SELECT id,printing_type_id,sub_type_name FROM printing_sub_types WHERE is_active=1 ORDER BY sort_order ASC,sub_type_name ASC"); while($r=$res->fetch_assoc()) $printingSubTypes[]=$r; $res->free(); } }catch(Throwable $e){}
try{ if(jc_table_exists($conn,'job_card_statuses')){ $name=jc_col($conn,'job_card_statuses','status_name')?'status_name':'name'; $res=$conn->query("SELECT id,{$name} status_name FROM job_card_statuses ORDER BY id ASC"); while($r=$res->fetch_assoc()) $statuses[]=$r; $res->free(); } }catch(Throwable $e){}try{ if(jc_table_exists($conn,'delay_reasons')){ $res=$conn->query("SELECT id,reason_name FROM delay_reasons WHERE is_active=1 ORDER BY id ASC"); while($r=$res->fetch_assoc()) $delayReasons[]=$r; $res->free(); } }catch(Throwable $e){}
try{ if(jc_table_exists($conn,'workflow_steps')){ $res=$conn->query("SELECT id,step_name,step_key,order_type FROM workflow_steps WHERE is_active=1 ORDER BY order_type ASC,sort_order ASC"); while($r=$res->fetch_assoc()) $workflowSteps[]=$r; $res->free(); } }catch(Throwable $e){}
try{
    if(jc_table_exists($conn,'proforma_bills')){
        $pbRemarks = jc_col($conn,'proforma_bills','remarks') ? 'pb.remarks' : "''";
        $pbiDescription = (jc_table_exists($conn,'proforma_bill_items') && jc_col($conn,'proforma_bill_items','description')) ? 'pbi.description' : "''";
        $pbiQty = (jc_table_exists($conn,'proforma_bill_items') && jc_col($conn,'proforma_bill_items','qty')) ? 'pbi.qty' : '1';
        $pbiRate = (jc_table_exists($conn,'proforma_bill_items') && jc_col($conn,'proforma_bill_items','rate')) ? 'pbi.rate' : '0';
        $pbiAmount = (jc_table_exists($conn,'proforma_bill_items') && jc_col($conn,'proforma_bill_items','amount')) ? 'pbi.amount' : 'pb.final_amount';
        $res=$conn->query("SELECT pb.id,pb.proforma_no,pb.customer_name,pb.mobile,pb.customer_id,pb.quotation_id,pb.function_type_id,pb.order_type,pb.final_amount,pb.advance_amount,pb.balance_amount,pb.delivery_date,{$pbRemarks} remarks,pbi.product_id,pbi.item_name,{$pbiDescription} description,{$pbiQty} qty,{$pbiRate} rate,{$pbiAmount} amount,pbi.printing_type_id,pbi.printing_sub_type_id,pbi.size_text,pbi.gsm_thickness,pbi.lamination_required,pbi.lamination_type,pbi.printing_side,pbi.screening_type,pbi.finishing_required FROM proforma_bills pb LEFT JOIN proforma_bill_items pbi ON pbi.proforma_bill_id=pb.id ORDER BY pb.id DESC LIMIT 300");
        while($r=$res->fetch_assoc()) $proformas[]=$r;
        $res->free();
    }
}catch(Throwable $e){}

/* Backend processing moved to api/job_cards.php */
$msg=(string)($_GET['msg']??'');
if($msg==='created'){ $message='Job card saved successfully.'; $messageType='success'; $toastTitle='Success'; }
elseif($msg==='updated'){ $message='Job card updated successfully.'; $messageType='success'; $toastTitle='Success'; }
elseif($msg==='deleted'){ $message='Job card disabled successfully.'; $messageType='success'; $toastTitle='Success'; }
elseif($msg==='failed'){ $message='Action failed. Please try again.'; $messageType='danger'; $toastTitle='Failed'; }
if(isset($_GET['err']) && trim((string)$_GET['err'])!==''){ $message .= ($message!==''?' ':'').'Error: '.trim((string)$_GET['err']); }
$rows=[]; if(jc_table_exists($conn,'job_cards')){ try{ $jobNoExpr=jc_col($conn,'job_cards','job_card_no')?'jc.job_card_no':(jc_col($conn,'job_cards','job_no')?'jc.job_no':"CONCAT('JOB-',jc.id)"); $statusJoin=jc_table_exists($conn,'job_card_statuses')&&jc_col($conn,'job_cards','job_card_status_id')?'LEFT JOIN job_card_statuses jcs ON jcs.id=jc.job_card_status_id':''; $workflowJoin=jc_table_exists($conn,'workflow_steps')&&jc_col($conn,'job_cards','current_workflow_step_id')?'LEFT JOIN workflow_steps ws ON ws.id=jc.current_workflow_step_id':''; $printingJoin=jc_table_exists($conn,'printing_types')&&jc_col($conn,'job_cards','printing_type_id')?'LEFT JOIN printing_types pt ON pt.id=jc.printing_type_id':''; $subJoin=jc_table_exists($conn,'printing_sub_types')&&jc_col($conn,'job_cards','printing_sub_type_id')?'LEFT JOIN printing_sub_types pst ON pst.id=jc.printing_sub_type_id':''; $itemJoin=jc_table_exists($conn,'job_card_items')?'LEFT JOIN job_card_items jci ON jci.job_card_id=jc.id':''; $statusExpr="COALESCE(".(jc_table_exists($conn,'job_card_statuses')&&jc_col($conn,'job_card_statuses','status_name')?'jcs.status_name,':'').(jc_col($conn,'job_cards','status')?'jc.status,':'')."'Active')"; $itemSelect=jc_table_exists($conn,'job_card_items')?'jci.item_name,jci.qty,jci.rate,jci.amount,jci.size_text,jci.gsm_thickness,jci.lamination_required,jci.lamination_type,jci.printing_side,jci.screening_type,jci.finishing_required':'NULL item_name,NULL qty,NULL rate,NULL amount,NULL size_text,NULL gsm_thickness,NULL lamination_required,NULL lamination_type,NULL printing_side,NULL screening_type,NULL finishing_required'; $sql="SELECT jc.*,{$jobNoExpr} display_job_no,{$statusExpr} display_status,".(jc_table_exists($conn,'workflow_steps')?'ws.step_name':'NULL')." current_step_name,".(jc_table_exists($conn,'printing_types')?'pt.printing_name':'NULL')." printing_name,".(jc_table_exists($conn,'printing_sub_types')?'pst.sub_type_name':'NULL')." sub_type_name,{$itemSelect} FROM job_cards jc {$statusJoin} {$workflowJoin} {$printingJoin} {$subJoin} {$itemJoin} ORDER BY jc.id DESC LIMIT 300"; $res=$conn->query($sql); while($r=$res->fetch_assoc())$rows[]=$r; $res->free(); }catch(Throwable $e){ $message=$message?:$e->getMessage(); $messageType='danger'; $toastTitle='Failed'; } }
$totalRows=count($rows); $activeRows=0; $customizedRows=0; $readymadeRows=0; foreach($rows as $r){ if(strtolower((string)($r['display_status']??'active'))!=='inactive')$activeRows++; if(($r['order_type']??'')==='customized')$customizedRows++; if(($r['order_type']??'')==='readymade')$readymadeRows++; }
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Cards - Subhiksha Cards</title>
    <?php include __DIR__.'/includes/links.php'; ?><?php include __DIR__.'/includes/theme-loader.php'; ?><style>
    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d
    }

    .toast-ui.warning {
        background: #fef3c7;
        color: #78350f
    }

    .toast-ui .toast-title {
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 2px
    }

    .toast-ui .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45
    }

    .view-info-card {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%
    }

    .view-info-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 4px
    }

    .view-info-card strong,
    .view-info-card span {
        display: block;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word;
        white-space: pre-wrap
    }

    .proforma-clear-btn {
        min-height: 46px;
        white-space: nowrap
    }


    .record-proforma-wrap {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px;
        align-items: start;
        width: 100%;
    }

    .record-proforma-wrap .select2-container {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .record-proforma-wrap .select2-selection {
        width: 100% !important;
    }

    .proforma-clear-btn {
        min-height: 46px;
        white-space: nowrap;
        flex: 0 0 auto;
    }

    #recordModal .modal-body {
        max-height: calc(100vh - 205px);
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: 28px;
    }

    #recordModal .select2-container--open {
        z-index: 20000 !important;
    }

    .select2-dropdown {
        z-index: 20000 !important;
        max-width: 100% !important;
    }

    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .module-card {
        padding: 24px
    }

    .module-title,
    .section-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px
    }

    .modal-content {
        border: 0;
        border-radius: 22px;
        background: var(--card-bg);
        color: var(--text-main)
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border-soft)
    }

    .stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto
    }

    .stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase
    }

    .stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main)
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px;
        color: var(--info-color);
        background: color-mix(in srgb, var(--info-color) 14%, transparent)
    }

    .status-pill.active,
    .status-pill.completed {
        color: var(--success-color);
        background: color-mix(in srgb, var(--success-color) 14%, transparent)
    }

    .status-pill.pending,
    .status-pill.in_progress {
        color: var(--warning-color);
        background: color-mix(in srgb, var(--warning-color) 14%, transparent)
    }

    .status-pill.inactive {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent)
    }

    .small-muted {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700
    }

    .requirement-box {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--card-bg) 94%, var(--body-bg));
        font-size: 13px;
        font-weight: 800;
        color: var(--text-main)
    }

    .delay-box{border:1px dashed var(--danger-color,#dc2626);border-radius:16px;padding:14px;background:color-mix(in srgb,var(--danger-color,#dc2626) 7%,var(--card-bg));}.delay-box small{font-weight:800;color:var(--danger-color,#dc2626)}

    .requirement-box strong {
        display: block;
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 4px
    }

    .customized-info {
        border-color: color-mix(in srgb, var(--brand-1, #f59e0b) 45%, var(--border-soft));
        background: color-mix(in srgb, var(--brand-1, #f59e0b) 8%, var(--card-bg))
    }

    .readymade-info {
        border-color: color-mix(in srgb, var(--info-color, #0ea5e9) 40%, var(--border-soft));
        background: color-mix(in srgb, var(--info-color, #0ea5e9) 8%, var(--card-bg))
    }

    .printing-locked,
    .printing-locked+.select2-container .select2-selection {
        background: color-mix(in srgb, var(--success-color, #16a34a) 8%, var(--card-bg)) !important;
        border-color: color-mix(in srgb, var(--success-color, #16a34a) 45%, var(--border-soft)) !important
    }

    .mobile-cards {
        display: none
    }

    .mobile-card {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main)
    }

    .mobile-card-subtitle {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word
    }

    .mobile-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px
        }

        .module-page .page-head h1 {
            font-size: 24px
        }

        .module-page .page-head .btn {
            width: 100%
        }

        .module-card {
            padding: 16px;
            border-radius: 18px
        }

        .desktop-table {
            display: none !important
        }

        .mobile-cards {
            display: block
        }

        .mobile-card-actions .btn,
        .mobile-card-actions form {
            flex: 1 1 auto
        }

        .mobile-card-actions .btn {
            width: 100%
        }
    }

    /* Job Cards UI + action icon fix */
    .btn-action-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
    }

    .btn-action-icon svg {
        display: block !important;
        width: 16px !important;
        height: 16px !important;
        color: currentColor !important;
        stroke: currentColor !important;
    }

    #dataTable {
        table-layout: fixed;
        width: 100%;
        min-width: 1120px;
    }

    #dataTable th,
    #dataTable td {
        vertical-align: middle !important;
    }

    #dataTable td.text-end {
        white-space: nowrap;
    }

    #dataTable td.text-end>button,
    #dataTable td.text-end>form {
        margin-left: 6px;
    }

    #dataTable td.text-end form {
        display: inline-flex;
        margin-bottom: 0;
    }

    @media(max-width:767.98px) {
        .mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .mobile-card>.d-flex.justify-content-between {
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .mobile-card .status-pill {
            align-self: flex-start !important;
            flex: 0 0 auto !important;
            min-width: auto !important;
            max-width: 125px !important;
            height: auto !important;
            min-height: 0 !important;
            line-height: 1.2 !important;
            padding: 6px 10px !important;
            border-radius: 999px !important;
            white-space: normal !important;
            text-align: center !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 10px !important;
        }

        .mobile-card-actions {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            margin-top: 14px !important;
        }

        .mobile-card-actions .btn-action-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
        }

        .mobile-card-actions .btn-action-icon svg {
            width: 18px !important;
            height: 18px !important;
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>
    <div class="app-shell"><?php include __DIR__.'/includes/sidebar.php'; ?><main id="main">
            <?php include __DIR__.'/includes/nav.php'; ?><section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Job Cards</h1>
                            <p class="text-muted-custom mb-0">Manage readymade and customized job cards with Select2
                                dropdowns.</p>
                        </div><button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">Create New</button>
                    </div>
                </div><?php if($message!==''): ?><div class="toast-container position-fixed top-0 end-0 p-3"
                    style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($toastTitle) ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div><?php endif; ?><div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i
                                    data-lucide="briefcase-business"></i></div>
                            <div><span>Total Jobs</span><strong><?= number_format($totalRows) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i
                                    data-lucide="check-circle-2"></i></div>
                            <div><span>Active</span><strong><?= number_format($activeRows) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i
                                    data-lucide="palette"></i></div>
                            <div><span>Customized</span><strong><?= number_format($customizedRows) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#7c3aed,#a855f7)"><i
                                    data-lucide="package-check"></i></div>
                            <div><span>Readymade</span><strong><?= number_format($readymadeRows) ?></strong></div>
                        </div>
                    </div>
                </div>
                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Job Cards List</h2>
                            <p class="text-muted-custom mb-0">Desktop table and mobile card view.</p>
                        </div>
                        <div style="max-width:340px;width:100%"><input type="search" id="tableSearch"
                                class="form-control" placeholder="Search..."></div>
                    </div>
                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Job No</th>
                                    <th>Customer</th>
                                    <th>Order Type</th>
                                    <th>Product</th>
                                    <th>Printing</th>
                                    <th>Delivery</th>
                                    <th>Stage</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody><?php if(!$rows): ?><tr>
                                    <td colspan="9" class="text-center text-muted-custom py-4">No records found.</td>
                                </tr>
                                <?php endif; ?><?php foreach($rows as $row): $rowStatus=strtolower(str_replace(' ','_',(string)($row['display_status']??'active'))); ?>
                                <tr>
                                    <td class="fw-bold"><?= e($row['display_job_no']??'-') ?></td>
                                    <td>
                                        <div class="fw-bold"><?= e($row['customer_name']??'-') ?></div><small
                                            class="text-muted-custom"><?= e($row['mobile']??'-') ?></small>
                                    </td>
                                    <td class="text-capitalize"><?= e($row['order_type']??'-') ?></td>
                                    <td><?= e($row['product_name']??$row['item_name']??'-') ?></td>
                                    <td><?= e($row['printing_name']??'-') ?><?php if(!empty($row['sub_type_name'])): ?><small
                                            class="small-muted"><?= e($row['sub_type_name']) ?></small><?php endif; ?>
                                    </td>
                                    <td><?= e($row['delivery_date']??'-') ?></td>
                                    <td><?= e($row['current_step_name']??$row['job_stage']??'-') ?></td>
                                    <td><span
                                            class="status-pill <?= e($rowStatus) ?>"><?= e($row['display_status']??'Active') ?></span>
                                    </td>
                                    <td class="text-end"><a title="View" aria-label="View" href="job_card_view.php?id=<?= e($row['id']) ?>" target="_blank"
                                            class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon"><svg
                                                viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path
                                                    d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12 18.25 18.75 12 18.75 2.25 12 2.25 12Z"
                                                    fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                                <circle cx="12" cy="12" r="2.75" fill="none" stroke="currentColor"
                                                    stroke-width="2" />
                                            </svg></a><button type="button" title="Edit" aria-label="Edit"
                                            class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-row='<?= e(json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>'><svg
                                                viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M12 20h9" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" />
                                                <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"
                                                    fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                            </svg></button><?php if($rowStatus!=='inactive'): ?>
                                        <form method="post" action="api/job_cards.php"
                                            class="d-inline js-api-disable-form" onsubmit="return false;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input
                                                type="hidden" name="action" value="delete_record"><input type="hidden"
                                                name="id" value="<?= e($row['id']) ?>"><button type="submit"
                                                title="Disable" aria-label="Disable"
                                                class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-action-icon"><svg
                                                    viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor"
                                                        stroke-width="2" />
                                                    <path d="M9 9l6 6M15 9l-6 6" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" />
                                                </svg></button>
                                        </form><?php endif; ?>
                                    </td>
                                </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mobile-cards" id="mobileCards">
                        <?php foreach($rows as $row): $rowStatus=strtolower(str_replace(' ','_',(string)($row['display_status']??'active'))); ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['display_job_no']??'Job Card') ?></div>
                                    <span class="mobile-card-subtitle">Customer:
                                        <?= e($row['customer_name']??'-') ?></span><span
                                        class="mobile-card-subtitle">Order Type:
                                        <?= e($row['order_type']??'-') ?></span><span
                                        class="mobile-card-subtitle">Product:
                                        <?= e($row['product_name']??$row['item_name']??'-') ?></span><span
                                        class="mobile-card-subtitle">Printing:
                                        <?= e($row['printing_name']??'-') ?></span><span
                                        class="mobile-card-subtitle">Delivery:
                                        <?= e($row['delivery_date']??'-') ?></span>
                                </div><span
                                    class="status-pill <?= e($rowStatus) ?>"><?= e($row['display_status']??'Active') ?></span>
                            </div>
                            <div class="mobile-card-actions"><a title="View" aria-label="View" href="job_card_view.php?id=<?= e($row['id']) ?>" target="_blank"
                                    class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon"><svg
                                        viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12 18.25 18.75 12 18.75 2.25 12 2.25 12Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="2.75" fill="none" stroke="currentColor" stroke-width="2" />
                                    </svg></a><button type="button" title="Edit" aria-label="Edit"
                                    class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                    data-bs-toggle="modal" data-bs-target="#recordModal"
                                    data-row='<?= e(json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>'><svg
                                        viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M12 20h9" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" />
                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" />
                                    </svg></button>
                            </div>
                        </div><?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include __DIR__.'/includes/rightsidebar.php'; ?>
    </div>
    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <form method="post" action="api/job_cards.php" class="modal-content" id="jobCardForm"><input type="hidden"
                    name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action"
                    value="save_record"><input type="hidden" name="id" id="id"><input type="hidden" name="quotation_id"
                    id="quotation_id"><input type="hidden" name="customer_id" id="customer_id"><input type="hidden"
                    name="function_type_id" id="function_type_id">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="recordModalTitle">Create Job Card</h5><small
                            class="text-muted-custom fw-bold">Readymade / Customized requirement flow</small>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-bold">Job No</label><input name="job_card_no"
                                id="job_card_no" class="form-control" value="<?= e($nextJobNo) ?>"></div>
                        <div class="col-md-8"><label class="form-label fw-bold">Proforma / Sales Order</label>
                            <div class="record-proforma-wrap">
                                <div class="flex-grow-1"><select name="proforma_bill_id" id="proforma_bill_id"
                                        class="form-select select2-autotype" data-placeholder="Search proforma">
                                        <option value="">Direct Job Card</option><?php foreach($proformas as $pf): ?>
                                        <option value="<?= e($pf['id']) ?>"
                                            data-quotation-id="<?= e($pf['quotation_id']??'') ?>"
                                            data-customer-id="<?= e($pf['customer_id']??'') ?>"
                                            data-customer-name="<?= e($pf['customer_name']??'') ?>"
                                            data-mobile="<?= e($pf['mobile']??'') ?>"
                                            data-function-type-id="<?= e($pf['function_type_id']??'') ?>"
                                            data-order-type="<?= e($pf['order_type']??'readymade') ?>"
                                            data-final-amount="<?= e($pf['final_amount']??'0') ?>"
                                            data-advance-amount="<?= e($pf['advance_amount']??'0') ?>"
                                            data-balance-amount="<?= e($pf['balance_amount']??'0') ?>"
                                            data-delivery-date="<?= e($pf['delivery_date']??'') ?>"
                                            data-product-id="<?= e($pf['product_id']??'') ?>"
                                            data-product-name="<?= e($pf['item_name']??'') ?>"
                                            data-printing-type-id="<?= e($pf['printing_type_id']??'') ?>"
                                            data-printing-sub-type-id="<?= e($pf['printing_sub_type_id']??'') ?>"
                                            data-size-text="<?= e($pf['size_text']??'') ?>"
                                            data-gsm-thickness="<?= e($pf['gsm_thickness']??'') ?>"
                                            data-lamination-required="<?= e($pf['lamination_required']??'0') ?>"
                                            data-lamination-type="<?= e($pf['lamination_type']??'') ?>"
                                            data-printing-side="<?= e($pf['printing_side']??'') ?>"
                                            data-screening-type="<?= e($pf['screening_type']??'') ?>"
                                            data-finishing-required="<?= e($pf['finishing_required']??'0') ?>"
                                            data-qty="<?= e($pf['qty']??'1') ?>" data-rate="<?= e($pf['rate']??'0') ?>"
                                            data-amount="<?= e($pf['amount']??'0') ?>"
                                            data-description="<?= e($pf['description']??'') ?>"
                                            data-remarks="<?= e($pf['remarks']??'') ?>"
                                            data-proforma-no="<?= e($pf['proforma_no']??'') ?>">
                                            <?= e($pf['proforma_no']) ?> - <?= e($pf['customer_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select></div><button type="button"
                                    class="btn btn-outline-secondary rounded-pill px-3 fw-bold proforma-clear-btn"
                                    id="clearProformaBtn">Clear</button>
                            </div>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-bold">Order Type *</label><select
                                name="order_type" id="order_type" class="form-select select2-autotype" required
                                data-placeholder="Select order type">
                                <option value="readymade">Readymade</option>
                                <option value="customized">Customized</option>
                            </select></div>
                        <div class="col-12">
                            <div class="requirement-box readymade-info"><strong>Readymade Job Card</strong>Product,
                                Printing Type, Screen Print Sub-Type if Screen Print, With/Without Finishing, Advance,
                                Final, Balance, Delivery Date, Remarks.</div>
                            <div class="requirement-box customized-info d-none"><strong>Customized Job
                                    Card</strong>Product, Size, GSM, Lamination Required/Type, Single/Double Side,
                                Regular/Special Screening. Printing Type auto becomes Multicolor Offset Print.</div>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-bold">Customer Name *</label><input
                                name="customer_name" id="customer_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Mobile *</label><input name="mobile"
                                id="mobile" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Delivery Date *</label><input
                                type="date" name="delivery_date" id="delivery_date" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Product *</label><select
                                name="product_id" id="product_id" class="form-select select2-autotype"
                                data-placeholder="Search / type product" data-tags="true">
                                <option value="">Manual Product Name</option><?php foreach($products as $p): ?><option
                                    value="<?= e($p['id']) ?>" data-price="<?= e($p['default_price']??0) ?>">
                                    <?= e($p['product_name']) ?></option><?php endforeach; ?>
                            </select><small class="small-muted">Type new product name in same input.</small></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Product / Item Name *</label><input
                                name="product_name" id="product_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Printing Type *</label><select
                                name="printing_type_id" id="printing_type_id" class="form-select select2-autotype"
                                data-placeholder="Search printing type"
                                data-multicolor-id="<?= e($multiColorId??'') ?>">
                                <option value="">Select Printing Type</option><?php foreach($printingTypes as $t): ?>
                                <option value="<?= e($t['id']) ?>" data-printing-key="<?= e($t['printing_key']??'') ?>"
                                    data-normalized-key="<?= e($t['normalized_key']??'') ?>"
                                    data-printing-name="<?= e($t['printing_name']??'') ?>"><?= e($t['printing_name']) ?>
                                </option><?php endforeach; ?>
                            </select><small class="small-muted d-none" id="customizedPrintingHelp">Customized order
                                automatically uses Multicolor Offset Print.</small><small
                                class="text-danger fw-bold d-none" id="multiColorMissingHelp">Multicolor Offset Print is
                                missing in Printing Types master.</small></div>
                        <div class="col-md-4" id="screenSubTypeWrap"><label class="form-label fw-bold">Screen Print
                                Sub-Type</label><select name="printing_sub_type_id" id="printing_sub_type_id"
                                class="form-select select2-autotype" data-placeholder="Select UV / Foil">
                                <option value="">Select</option><?php foreach($printingSubTypes as $s): ?><option
                                    value="<?= e($s['id']) ?>" data-printing-type="<?= e($s['printing_type_id']) ?>">
                                    <?= e($s['sub_type_name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4 readymade-only"><label class="form-label fw-bold">Finishing Option
                                *</label><select name="finishing_required" id="finishing_required"
                                class="form-select select2-autotype">
                                <option value="1">With Finishing</option>
                                <option value="0">Without Finishing</option>
                            </select></div>
                        <div class="col-md-4 customized-only"><label class="form-label fw-bold">Size *</label><input
                                name="size_text" id="size_text" class="form-control"></div>
                        <div class="col-md-4 customized-only"><label class="form-label fw-bold">GSM Thickness
                                *</label><input name="gsm_thickness" id="gsm_thickness" class="form-control"></div>
                        <div class="col-md-4 customized-only"><label class="form-label fw-bold">Lamination
                                Required?</label><select name="lamination_required" id="lamination_required"
                                class="form-select select2-autotype">
                                <option value="0">No Lamination</option>
                                <option value="1">Lamination Required</option>
                            </select></div>
                        <div class="col-md-4 customized-only" id="laminationTypeWrap"><label
                                class="form-label fw-bold">Lamination Type</label><select name="lamination_type"
                                id="lamination_type" class="form-select select2-autotype">
                                <option value="">Select</option>
                                <option value="glossy">Glossy</option>
                                <option value="matte">Matte</option>
                                <option value="special">Special</option>
                            </select></div>
                        <div class="col-md-4 customized-only"><label class="form-label fw-bold">Printing Side
                                *</label><select name="printing_side" id="printing_side"
                                class="form-select select2-autotype">
                                <option value="">Select</option>
                                <option value="single">Single Side</option>
                                <option value="double">Double Side</option>
                            </select></div>
                        <div class="col-md-4 customized-only"><label class="form-label fw-bold">Screening
                                *</label><select name="screening_type" id="screening_type"
                                class="form-select select2-autotype">
                                <option value="">Select</option>
                                <option value="regular">Regular Screening</option>
                                <option value="special">Special Screening</option>
                            </select></div>
                        <div class="col-md-2"><label class="form-label fw-bold">Qty *</label><input type="number"
                                step="0.01" min="0.01" name="qty" id="qty" class="form-control" value="1" required>
                        </div>
                        <div class="col-md-2"><label class="form-label fw-bold">Rate</label><input type="number"
                                step="0.01" min="0" name="rate" id="rate" class="form-control" value="0"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Final Amount</label><input type="number"
                                step="0.01" min="0" name="final_amount" id="final_amount" class="form-control"
                                value="0"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Advance Amount</label><input
                                type="number" step="0.01" min="0" name="advance_amount" id="advance_amount"
                                class="form-control" value="0"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Balance Amount</label><input
                                type="number" step="0.01" min="0" name="balance_amount" id="balance_amount"
                                class="form-control" value="0" readonly></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Current Workflow Stage</label><select
                                name="current_workflow_step_id" id="current_workflow_step_id"
                                class="form-select select2-autotype">
                                <option value="">Auto Stage</option><?php foreach($workflowSteps as $s): ?><option
                                    value="<?= e($s['id']) ?>" data-order-type="<?= e($s['order_type']??'') ?>">
                                    <?= e($s['step_name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Job Status</label><select
                                name="job_card_status_id" id="job_card_status_id" class="form-select select2-autotype">
                                <option value="">Auto Status</option><?php foreach($statuses as $st): ?><option
                                    value="<?= e($st['id']) ?>"><?= e($st['status_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-12">
                            <div class="delay-box">
                                <label class="form-label fw-bold">Delay Reason</label>
                                <select name="delay_reason_id" id="delay_reason_id" class="form-select select2-autotype mb-2">
                                    <option value="">Select delay reason if this job/status is delayed</option>
                                    <?php foreach($delayReasons as $reason): ?>
                                    <option value="<?= e($reason['id']) ?>"><?= e($reason['reason_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea name="delay_remarks" id="delay_remarks" rows="2" class="form-control" placeholder="Enter delay remarks if the selected stage/status is delayed"></textarea>
                                <small>This is required when the selected job status/stage is delayed.</small>
                            </div>
                        </div>
                        <div class="col-12"><label class="form-label fw-bold">Remarks / Notes</label><textarea
                                name="notes" id="notes" rows="3" class="form-control"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button"
                        class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">Cancel</button><button type="submit"
                        class="btn btn-primary rounded-pill px-4 fw-bold" id="recordSubmitBtn">Save Job Card</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">View Job Card</h5>
                        <small class="text-muted-custom" id="viewJobNo"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="view-info-card"><small>Customer</small><strong id="viewCustomerName">-</strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="view-info-card"><small>Mobile</small><strong id="viewMobile">-</strong></div>
                        </div>
                        <div class="col-md-3">
                            <div class="view-info-card"><small>Order Type</small><strong id="viewOrderType">-</strong>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="view-info-card"><small>Status</small><strong id="viewStatus">-</strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Product</small><strong id="viewProductName">-</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Printing</small><strong id="viewPrinting">-</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Delivery Date</small><strong
                                    id="viewDeliveryDate">-</strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Final Amount</small><strong
                                    id="viewFinalAmount">-</strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Advance</small><strong id="viewAdvanceAmount">-</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Balance</small><strong id="viewBalanceAmount">-</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Size</small><strong id="viewSize">-</strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>GSM</small><strong id="viewGsm">-</strong></div>
                        </div>
                        <div class="col-md-4">
                            <div class="view-info-card"><small>Lamination</small><strong id="viewLamination">-</strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="view-info-card"><small>Remarks / Notes</small><span id="viewNotes">-</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__.'/includes/script.php'; ?>
    <script>
    (function() {
        function showToast(message, type = 'success', titleText = '') {
            if (!message) return;
            const old = document.getElementById('dynamicActionToastWrap');
            if (old) old.remove();
            const toastTitle = titleText || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
                'Success'));
            const wrap = document.createElement('div');
            wrap.id = 'dynamicActionToastWrap';
            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
            wrap.style.zIndex = '12000';
            wrap.innerHTML =
                `<div id="dynamicActionToast" class="toast toast-ui ${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200"><div class="d-flex"><div class="toast-body"><div class="toast-title">${toastTitle}</div><div class="toast-message">${message}</div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`;
            document.body.appendChild(wrap);
            const toastEl = document.getElementById('dynamicActionToast');
            if (window.bootstrap && bootstrap.Toast && toastEl) bootstrap.Toast.getOrCreateInstance(toastEl).show();
        }
        window.showToast = showToast;
        // Toast rule: important result messages only.
        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) bootstrap.Toast.getOrCreateInstance(pageToastEl)
            .show();

        function setText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = (value == null || String(value).trim() === '') ? '-' : String(value);
        }

        const nextJobNo = <?= json_encode($nextJobNo) ?>,
            modal = document.getElementById('recordModal'),
            form = document.getElementById('jobCardForm'),
            title = document.getElementById('recordModalTitle'),
            submit = document.getElementById('recordSubmitBtn');

        function byId(id) {
            return document.getElementById(id)
        }

        function refresh(el) {
            if (el && window.jQuery && $.fn.select2) $(el).trigger('change.select2')
        }

        function set(id, v) {
            const el = byId(id);
            if (!el) return;
            el.value = v == null ? '' : v;
            refresh(el)
        }

        function setS(id, v) {
            const el = byId(id);
            if (!el) return;
            el.value = v == null ? '' : v;
            if (window.jQuery && $.fn.select2) $('#' + id).val(el.value).trigger('change.select2')
        }

        function initS2() {
            if (!window.jQuery || !$.fn.select2) return;
            $(modal).find('.select2-autotype').each(function() {
                const s = $(this);
                if (s.data('select2')) s.select2('destroy');
                s.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $('#recordModal'),
                    dropdownAutoWidth: false,
                    tags: String(s.data('tags') || '') === 'true',
                    placeholder: s.data('placeholder') || 'Select',
                    allowClear: true
                });
            });
        }

        function norm(v) {
            return String(v || '').toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g,
                ' ').trim()
        }

        function optText(o) {
            return !o ? '' : norm((o.dataset.printingKey || '') + ' ' + (o.dataset.normalizedKey || '') + ' ' + (o
                .dataset.printingName || '') + ' ' + (o.textContent || ''))
        }

        function isScreen() {
            const p = byId('printing_type_id'),
                o = p?.options[p.selectedIndex];
            return optText(o).includes('screen')
        }

        function multiVal() {
            const p = byId('printing_type_id');
            if (!p) return '';
            const php = p.dataset.multicolorId || '';
            if (php && p.querySelector('option[value="' + php + '"]')) return php;
            const m = Array.from(p.options || []).find(o => {
                const t = optText(o);
                return o.value && t.includes('offset') && (t.includes('multicolor') || t.includes(
                    'multicolour') || t.includes('multi color') || (t.includes('multi') && t
                    .includes('color')));
            });
            return m ? m.value : ''
        }

        function filterSub() {
            const pt = byId('printing_type_id'),
                sub = byId('printing_sub_type_id');
            if (!pt || !sub) return;
            const type = pt.value;
            Array.from(sub.options).forEach(o => {
                if (!o.value) {
                    o.hidden = false;
                    o.disabled = false;
                    return
                }
                const ok = String(o.dataset.printingType || '') === String(type);
                o.hidden = !ok;
                o.disabled = !ok;
            });
            const sel = sub.options[sub.selectedIndex];
            if (sel && (sel.hidden || sel.disabled)) setS('printing_sub_type_id', '')
        }

        function toggle() {
            const ot = byId('order_type')?.value || 'readymade',
                custom = ot === 'customized';
            document.querySelectorAll('.customized-only').forEach(e => e.style.display = custom ? '' : 'none');
            document.querySelectorAll('.readymade-only').forEach(e => e.style.display = custom ? 'none' : '');
            document.querySelector('.customized-info')?.classList.toggle('d-none', !custom);
            document.querySelector('.readymade-info')?.classList.toggle('d-none', custom);
            if (custom) {
                const m = multiVal();
                if (m) {
                    setS('printing_type_id', m);
                    byId('printing_type_id')?.classList.add('printing-locked');
                    byId('customizedPrintingHelp')?.classList.remove('d-none');
                    byId('multiColorMissingHelp')?.classList.add('d-none')
                } else {
                    byId('customizedPrintingHelp')?.classList.add('d-none');
                    byId('multiColorMissingHelp')?.classList.remove('d-none')
                }
                setS('printing_sub_type_id', '');
                byId('screenSubTypeWrap')?.style.setProperty('display', 'none', 'important')
            } else {
                byId('printing_type_id')?.classList.remove('printing-locked');
                byId('customizedPrintingHelp')?.classList.add('d-none');
                byId('multiColorMissingHelp')?.classList.add('d-none');
                const sc = isScreen();
                byId('screenSubTypeWrap')?.style.setProperty('display', sc ? '' : 'none', 'important');
                if (!sc) setS('printing_sub_type_id', '')
            }
            const lam = byId('lamination_required')?.value === '1';
            const lw = byId('laminationTypeWrap');
            if (lw) lw.style.display = (custom && lam) ? '' : 'none';
            if (!lam) setS('lamination_type', '');
            const step = byId('current_workflow_step_id');
            if (step) {
                Array.from(step.options).forEach(o => {
                    if (!o.value) {
                        o.hidden = false;
                        o.disabled = false;
                        return
                    }
                    const ok = !o.dataset.orderType || o.dataset.orderType === ot;
                    o.hidden = !ok;
                    o.disabled = !ok;
                });
            }
        }

        function calc() {
            const f = parseFloat(byId('final_amount')?.value || '0') || 0,
                a = parseFloat(byId('advance_amount')?.value || '0') || 0;
            if (byId('balance_amount')) byId('balance_amount').value = Math.max(0, f - a).toFixed(2)
        }

        function reset() {
            form.reset();
            ['id', 'quotation_id', 'customer_id', 'function_type_id'].forEach(i => set(i, ''));
            set('job_card_no', nextJobNo);
            set('qty', '1');
            set('rate', '0');
            set('final_amount', '0');
            set('advance_amount', '0');
            set('balance_amount', '0');
            ['proforma_bill_id', 'product_id', 'printing_type_id', 'printing_sub_type_id',
                'current_workflow_step_id', 'job_card_status_id', 'printing_side', 'screening_type',
                'lamination_type'
            ].forEach(i => setS(i, ''));
            setS('order_type', 'readymade');
            setS('finishing_required', '1');
            setS('lamination_required', '0');
            title.textContent = 'Create Job Card';
            submit.textContent = 'Save Job Card';
            setTimeout(toggle, 80)
        }

        function applyPF() {
            const sel = byId('proforma_bill_id'),
                o = sel?.options[sel.selectedIndex];

            if (!o || !o.value) return;

            set('quotation_id', o.dataset.quotationId || '');
            set('customer_id', o.dataset.customerId || '');
            set('function_type_id', o.dataset.functionTypeId || '');
            set('customer_name', o.dataset.customerName || '');
            set('mobile', o.dataset.mobile || '');

            setS('order_type', o.dataset.orderType || 'readymade');

            set('final_amount', o.dataset.finalAmount || '0');
            set('advance_amount', o.dataset.advanceAmount || '0');
            set('balance_amount', o.dataset.balanceAmount || '0');
            set('delivery_date', o.datasetDeliveryDate || o.dataset.deliveryDate || '');

            setS('product_id', o.dataset.productId || '');
            set('product_name', o.dataset.productName || '');

            set('qty', o.dataset.qty || '1');
            set('rate', o.dataset.rate || '0');

            setS('printing_type_id', o.dataset.printingTypeId || '');
            setS('printing_sub_type_id', o.dataset.printingSubTypeId || '');

            set('size_text', o.dataset.sizeText || '');
            set('gsm_thickness', o.dataset.gsmThickness || '');

            setS('lamination_required', o.dataset.laminationRequired || '0');
            setS('lamination_type', o.dataset.laminationType || '');

            setS('printing_side', o.dataset.printingSide || '');
            setS('screening_type', o.dataset.screeningType || '');
            setS('finishing_required', o.dataset.finishingRequired || '0');

            set('notes', o.dataset.description || o.dataset.remarks || '');

            calc();

            setTimeout(() => {
                filterSub();
                toggle();
                calc();
            }, 120);
        }

        document.querySelectorAll('.js-view-record').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.row || '{}');
            setText('viewJobNo', r.display_job_no || r.job_card_no || r.job_no || '-');
            setText('viewCustomerName', r.customer_name || '-');
            setText('viewMobile', r.mobile || '-');
            setText('viewOrderType', r.order_type || '-');
            setText('viewStatus', r.display_status || 'Active');
            setText('viewProductName', r.product_name || r.item_name || '-');
            setText('viewPrinting', (r.printing_name || '-') + (r.sub_type_name ? ' / ' + r
                .sub_type_name : ''));
            setText('viewDeliveryDate', r.delivery_date || '-');
            setText('viewFinalAmount', r.final_amount ? ('₹' + parseFloat(r.final_amount || 0).toFixed(
                2)) : '-');
            setText('viewAdvanceAmount', r.advance_amount ? ('₹' + parseFloat(r.advance_amount || 0)
                .toFixed(2)) : '-');
            setText('viewBalanceAmount', r.balance_amount ? ('₹' + parseFloat(r.balance_amount || 0)
                .toFixed(2)) : '-');
            setText('viewSize', r.size_text || '-');
            setText('viewGsm', r.gsm_thickness || '-');
            setText('viewLamination', (String(r.lamination_required || '0') === '1' ? 'Required' :
                'No Lamination') + (r.lamination_type ? ' / ' + r.lamination_type : ''));
            setText('viewNotes', r.notes || '-');
        }));

        document.getElementById('clearProformaBtn')?.addEventListener('click', () => {
            setS('proforma_bill_id', '');
            ['quotation_id', 'customer_id', 'function_type_id', 'customer_name', 'mobile', 'product_name',
                'size_text', 'gsm_thickness', 'notes', 'delivery_date'
            ].forEach(i => set(i, ''));
            ['product_id', 'printing_type_id', 'printing_sub_type_id', 'printing_side', 'screening_type',
                'lamination_type', 'current_workflow_step_id'
            ].forEach(i => setS(i, ''));
            setS('order_type', 'readymade');
            setS('finishing_required', '1');
            setS('lamination_required', '0');
            set('qty', '1');
            set('rate', '0');
            set('final_amount', '0');
            set('advance_amount', '0');
            set('balance_amount', '0');
            toggle();
        });

        document.getElementById('newRecordBtn')?.addEventListener('click', () => setTimeout(() => {
            initS2();
            reset()
        }, 120));
        document.querySelectorAll('.js-edit-record').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.row || '{}');
            setTimeout(() => {
                initS2();
                title.textContent = 'Edit Job Card';
                submit.textContent = 'Update Job Card';
                set('id', r.id || '');
                set('job_card_no', r.display_job_no || r.job_card_no || r.job_no || '');
                set('customer_id', r.customer_id || '');
                set('quotation_id', r.quotation_id || '');
                set('function_type_id', r.function_type_id || '');
                set('customer_name', r.customer_name || '');
                set('mobile', r.mobile || '');
                set('delivery_date', r.delivery_date || '');
                set('product_name', r.product_name || r.item_name || '');
                set('qty', r.qty || '1');
                set('rate', r.rate || '0');
                set('final_amount', r.final_amount || '0');
                set('advance_amount', r.advance_amount || '0');
                set('balance_amount', r.balance_amount || '0');
                set('size_text', r.size_text || '');
                set('gsm_thickness', r.gsm_thickness || '');
                set('notes', r.notes || '');
                setS('proforma_bill_id', r.proforma_bill_id || '');
                setS('order_type', r.order_type || 'readymade');
                setS('product_id', r.product_id || '');
                setS('printing_type_id', r.printing_type_id || '');
                setS('printing_sub_type_id', r.printing_sub_type_id || '');
                setS('finishing_required', r.finishing_required || '0');
                setS('lamination_required', r.lamination_required || '0');
                setS('lamination_type', r.lamination_type || '');
                setS('printing_side', r.printing_side || '');
                setS('screening_type', r.screening_type || '');
                setS('current_workflow_step_id', r.current_workflow_step_id || '');
                setS('job_card_status_id', r.job_card_status_id || '');
                calc();
                setTimeout(toggle, 80)
            }, 120)
        }));
        $(document).on('change select2:select', '#proforma_bill_id', applyPF);
        $(document).on('change select2:select', '#order_type,#printing_type_id,#lamination_required', () =>
            setTimeout(() => {
                filterSub();
                toggle()
            }, 30));
        $(document).on('change select2:select', '#product_id', function() {
            const o = this.options[this.selectedIndex];
            if (o && o.textContent.trim() && o.textContent.trim() !== 'Manual Product Name') set(
                'product_name', o.textContent.trim());
            if (o?.dataset.price) set('rate', o.dataset.price)
        });
        $('#final_amount,#advance_amount').on('input', calc);

        form?.addEventListener('submit', function(event) {
            event.preventDefault();

            const formData = new FormData(form);

            fetch('api/job_cards.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'Job card saved successfully.' :
                            'Job card save failed.'), data.status ? 'success' : 'danger', data
                        .status ? 'Success' : 'Failed');

                    if (data.status) {
                        setTimeout(() => window.location.reload(), 900);
                    }
                })
                .catch(() => showToast('Request failed. Please try again.', 'danger', 'Failed'));
        });

        document.querySelectorAll('.js-api-disable-form').forEach(function(disableForm) {
            disableForm.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            disableForm.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                const ok = confirm('Disable this job card?');
                if (!ok) return;

                const formData = new FormData(disableForm);

                fetch('api/job_cards.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ?
                                'Job card disabled successfully.' :
                                'Job card disable failed.'), data.status ? 'success' :
                            'danger', data.status ? 'Success' : 'Failed');

                        if (data.status) {
                            setTimeout(() => window.location.reload(), 900);
                        }
                    })
                    .catch(() => showToast('Request failed. Please try again.', 'danger',
                        'Failed'));
            });
        });

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const v = this.value.toLowerCase().trim();
            document.querySelectorAll('#dataTable tbody tr').forEach(r => r.style.display = r.textContent
                .toLowerCase().includes(v) ? '' : 'none');
            document.querySelectorAll('#mobileCards .mobile-card').forEach(c => c.style.display = c
                .textContent.toLowerCase().includes(v) ? '' : 'none')
        });
        document.addEventListener('shown.bs.modal', e => {
            if (e.target?.id === 'recordModal') {
                initS2();
                toggle()
            }
        });
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    })();
    </script>
</body>

</html>