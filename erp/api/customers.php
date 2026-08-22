<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function cm_json($ok, $message = '', $extra = []) {
    echo json_encode(array_merge(['status'=>(bool)$ok,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function cm_table($conn,$table){try{$s=$conn->real_escape_string($table);$r=$conn->query("SHOW TABLES LIKE '{$s}'");$ok=$r&&$r->num_rows>0;if($r)$r->free();return $ok;}catch(Throwable $e){return false;}}
function cm_col($conn,$table,$col){static $c=[];$k=$table.'.'.$col;if(isset($c[$k]))return $c[$k];try{$t=$conn->real_escape_string($table);$x=$conn->real_escape_string($col);$r=$conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$x}'");$ok=$r&&$r->num_rows>0;if($r)$r->free();return $c[$k]=$ok;}catch(Throwable $e){return $c[$k]=false;}}
function cm_admin($conn){
    $rk=strtolower(trim((string)($_SESSION['role_key']??$_SESSION['role']??'')));$rn=strtolower(trim((string)($_SESSION['role_name']??'')));
    if(in_array($rk,['admin','super_admin','superadmin','business_admin'],true)||$rn==='admin')return true;
    $id=(int)($_SESSION['role_id']??0);if($id<=0)return false;
    try{$s=$conn->prepare("SELECT role_key,role_name FROM roles WHERE id=? LIMIT 1");$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();if(!$r)return false;$rk=strtolower((string)$r['role_key']);$rn=strtolower((string)$r['role_name']);return in_array($rk,['admin','super_admin','superadmin','business_admin'],true)||$rn==='admin';}catch(Throwable $e){return false;}
}
function cm_allowed($conn,$action){
    if(cm_admin($conn))return true;
    $map=['view'=>'can_view','create'=>'can_create','edit'=>'can_edit','update'=>'can_update'];$fn=$map[$action]??'can_view';
    if(function_exists($fn)){try{return (bool)$fn($conn,'customers.php');}catch(Throwable $e){}}
    if(function_exists('permission_allowed')){try{return (bool)permission_allowed($conn,$fn,'customers.php');}catch(Throwable $e){}}
    return false;
}
function cm_csrf(){ $t=(string)($_POST['csrf_token']??''); if($t===''||empty($_SESSION['customers_csrf'])||!hash_equals((string)$_SESSION['customers_csrf'],$t)) cm_json(false,'Invalid CSRF token. Refresh and try again.'); }
function cm_customer($conn,$id){$s=$conn->prepare("SELECT * FROM customers WHERE id=? LIMIT 1");$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;}
function cm_scalar($conn,$sql,$types='',$params=[],$default=0){try{$s=$conn->prepare($sql);if($types!=='')$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_row();$s->close();return $r?$r[0]:$default;}catch(Throwable $e){return $default;}}
function cm_money($v){return '₹'.number_format((float)$v,2);}
function cm_history($conn,$kind,$c){
    $out=[];$id=(int)$c['id'];$mobile=(string)$c['mobile'];
    try{
        if($kind==='enquiries'&&cm_table($conn,'enquiries')){$s=$conn->prepare("SELECT enquiry_no,function_date,created_at FROM enquiries WHERE customer_id=? ORDER BY id DESC LIMIT 15");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$out[]=['title'=>$x['enquiry_no'],'amount_text'=>'','meta'=>'Function: '.(!empty($x['function_date'])?date('d-m-Y',strtotime($x['function_date'])):'-').' · Created: '.date('d-m-Y h:i A',strtotime($x['created_at']))];$s->close();}
        if($kind==='quotations'&&cm_table($conn,'quotations')){$s=$conn->prepare("SELECT quotation_no,final_amount,created_at FROM quotations WHERE customer_id=? ORDER BY id DESC LIMIT 15");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$out[]=['title'=>$x['quotation_no'],'amount_text'=>cm_money($x['final_amount']),'meta'=>'Created: '.date('d-m-Y h:i A',strtotime($x['created_at']))];$s->close();}
        if($kind==='proformas'&&cm_table($conn,'proforma_bills')){$s=$conn->prepare("SELECT proforma_no,final_amount,advance_amount,balance_amount,created_at FROM proforma_bills WHERE customer_id=? ORDER BY id DESC LIMIT 15");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$out[]=['title'=>$x['proforma_no'],'amount_text'=>cm_money($x['final_amount']),'meta'=>'Paid: '.cm_money($x['advance_amount']).' · Balance: '.cm_money($x['balance_amount'])];$s->close();}
        if($kind==='quick_sales'&&cm_table($conn,'quick_sales')){if(cm_col($conn,'quick_sales','customer_id')){$s=$conn->prepare("SELECT sale_no,total_amount,created_at FROM quick_sales WHERE customer_id=? ORDER BY id DESC LIMIT 15");$s->bind_param('i',$id);}elseif(cm_col($conn,'quick_sales','mobile')){$s=$conn->prepare("SELECT sale_no,total_amount,created_at FROM quick_sales WHERE mobile=? ORDER BY id DESC LIMIT 15");$s->bind_param('s',$mobile);}else{return [];} $s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$out[]=['title'=>$x['sale_no'],'amount_text'=>cm_money($x['total_amount']),'meta'=>'Sale: '.date('d-m-Y h:i A',strtotime($x['created_at']))];$s->close();}
        if($kind==='payments'&&cm_table($conn,'payments')){$cancel=cm_col($conn,'payments','is_cancelled')?' AND COALESCE(p.is_cancelled,0)=0 ':'';$s=$conn->prepare("SELECT p.payment_no,p.amount,p.payment_mode,p.payment_date,pb.proforma_no FROM payments p LEFT JOIN proforma_bills pb ON pb.id=p.proforma_bill_id WHERE p.customer_id=? {$cancel} ORDER BY p.id DESC LIMIT 15");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$out[]=['title'=>$x['payment_no'],'amount_text'=>cm_money($x['amount']),'meta'=>'Proforma: '.($x['proforma_no']?:'-').' · '.strtoupper((string)$x['payment_mode']).' · '.(!empty($x['payment_date'])?date('d-m-Y',strtotime($x['payment_date'])):'-')];$s->close();}
    }catch(Throwable $e){return [];}
    return $out;
}

if(!cm_allowed($conn,'view')) cm_json(false,'You do not have permission to view customers.');
if(!cm_table($conn,'customers')) cm_json(false,'customers table is missing.');
if($_SERVER['REQUEST_METHOD']==='POST') cm_csrf();
$action=strtolower(trim((string)($_REQUEST['action']??'list')));

try{
    if($action==='summary'){
        cm_json(true,'',['summary'=>[
            'total'=>(int)cm_scalar($conn,"SELECT COUNT(*) FROM customers"),
            'active'=>(int)cm_scalar($conn,"SELECT COUNT(*) FROM customers WHERE is_active=1"),
            'business'=>(int)cm_scalar($conn,"SELECT COUNT(*) FROM customers WHERE customer_type='business'"),
            'individual'=>(int)cm_scalar($conn,"SELECT COUNT(*) FROM customers WHERE customer_type='individual'")
        ]]);
    }
    if($action==='list'){
        $q=trim((string)($_GET['q']??''));$type=strtolower(trim((string)($_GET['customer_type']??'')));$status=trim((string)($_GET['status']??''));$page=max(1,(int)($_GET['page']??1));$pp=min(100,max(5,(int)($_GET['per_page']??15)));$offset=($page-1)*$pp;
        if(!in_array($type,['','individual','business'],true))$type='';if(!in_array($status,['','0','1'],true))$status='';
        $w=['1=1'];$types='';$p=[];
        if($q!==''){$w[]="(c.customer_name LIKE CONCAT('%',?,'%') OR c.mobile LIKE CONCAT('%',?,'%') OR COALESCE(c.alternate_mobile,'') LIKE CONCAT('%',?,'%') OR COALESCE(c.email,'') LIKE CONCAT('%',?,'%') OR COALESCE(c.business_name,'') LIKE CONCAT('%',?,'%') OR COALESCE(c.gst_number,'') LIKE CONCAT('%',?,'%') OR COALESCE(c.city,'') LIKE CONCAT('%',?,'%'))";$types.='sssssss';for($i=0;$i<7;$i++)$p[]=$q;}
        if($type!==''){$w[]='c.customer_type=?';$types.='s';$p[]=$type;} if($status!==''){$w[]='c.is_active=?';$types.='i';$p[]=(int)$status;}$ws=implode(' AND ',$w);
        $s=$conn->prepare("SELECT COUNT(*) FROM customers c WHERE {$ws}");if($types!=='')$s->bind_param($types,...$p);$s->execute();$total=(int)$s->get_result()->fetch_row()[0];$s->close();
        $enq=cm_table($conn,'enquiries')?"(SELECT COUNT(*) FROM enquiries e WHERE e.customer_id=c.id)":'0';$qt=cm_table($conn,'quotations')?"(SELECT COUNT(*) FROM quotations q WHERE q.customer_id=c.id)":'0';$pf=cm_table($conn,'proforma_bills')?"(SELECT COUNT(*) FROM proforma_bills pb WHERE pb.customer_id=c.id)":'0';$pfAmt=cm_table($conn,'proforma_bills')?"COALESCE((SELECT SUM(pb.final_amount) FROM proforma_bills pb WHERE pb.customer_id=c.id),0)":'0';$qsAmt='0';
        if(cm_table($conn,'quick_sales')){$qsAmt=cm_col($conn,'quick_sales','customer_id')?"COALESCE((SELECT SUM(qs.total_amount) FROM quick_sales qs WHERE qs.customer_id=c.id),0)":(cm_col($conn,'quick_sales','mobile')?"COALESCE((SELECT SUM(qs.total_amount) FROM quick_sales qs WHERE qs.mobile=c.mobile),0)":'0');}
        $sql="SELECT c.*,{$enq} enquiry_count,{$qt} quotation_count,{$pf} proforma_count,({$pfAmt}+{$qsAmt}) total_business FROM customers c WHERE {$ws} ORDER BY c.id DESC LIMIT ? OFFSET ?";$s=$conn->prepare($sql);$mt=$types.'ii';$mp=array_merge($p,[$pp,$offset]);$s->bind_param($mt,...$mp);$s->execute();$r=$s->get_result();$rows=[];while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
        cm_json(true,'',['rows'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$pp,'total_rows'=>$total,'total_pages'=>max(1,(int)ceil($total/$pp))]]);
    }
    if($action==='get'){$id=(int)($_GET['id']??0);$c=$id>0?cm_customer($conn,$id):null;if(!$c)cm_json(false,'Customer not found.');cm_json(true,'',['customer'=>$c]);}
    if($action==='profile'){
        $id=(int)($_GET['id']??0);$c=$id>0?cm_customer($conn,$id):null;if(!$c)cm_json(false,'Customer not found.');$mobile=(string)$c['mobile'];
        $s=['enquiries'=>cm_table($conn,'enquiries')?(int)cm_scalar($conn,"SELECT COUNT(*) FROM enquiries WHERE customer_id=?",'i',[$id]):0,'quotations'=>cm_table($conn,'quotations')?(int)cm_scalar($conn,"SELECT COUNT(*) FROM quotations WHERE customer_id=?",'i',[$id]):0,'proformas'=>cm_table($conn,'proforma_bills')?(int)cm_scalar($conn,"SELECT COUNT(*) FROM proforma_bills WHERE customer_id=?",'i',[$id]):0,'quick_sales'=>0,'proforma_amount'=>cm_table($conn,'proforma_bills')?(float)cm_scalar($conn,"SELECT COALESCE(SUM(final_amount),0) FROM proforma_bills WHERE customer_id=?",'i',[$id]):0,'quick_sale_amount'=>0,'pending_balance'=>cm_table($conn,'proforma_bills')?(float)cm_scalar($conn,"SELECT COALESCE(SUM(balance_amount),0) FROM proforma_bills WHERE customer_id=?",'i',[$id]):0];
        if(cm_table($conn,'quick_sales')){if(cm_col($conn,'quick_sales','customer_id')){$s['quick_sales']=(int)cm_scalar($conn,"SELECT COUNT(*) FROM quick_sales WHERE customer_id=?",'i',[$id]);$s['quick_sale_amount']=(float)cm_scalar($conn,"SELECT COALESCE(SUM(total_amount),0) FROM quick_sales WHERE customer_id=?",'i',[$id]);}elseif(cm_col($conn,'quick_sales','mobile')){$s['quick_sales']=(int)cm_scalar($conn,"SELECT COUNT(*) FROM quick_sales WHERE mobile=?",'s',[$mobile]);$s['quick_sale_amount']=(float)cm_scalar($conn,"SELECT COALESCE(SUM(total_amount),0) FROM quick_sales WHERE mobile=?",'s',[$mobile]);}}
        $s['total_business']=$s['proforma_amount']+$s['quick_sale_amount'];cm_json(true,'',['customer'=>$c,'summary'=>$s,'history'=>['enquiries'=>cm_history($conn,'enquiries',$c),'quotations'=>cm_history($conn,'quotations',$c),'proformas'=>cm_history($conn,'proformas',$c),'quick_sales'=>cm_history($conn,'quick_sales',$c),'payments'=>cm_history($conn,'payments',$c)]]);
    }
    if($action==='save'){
        $id=(int)($_POST['id']??0);if($id>0){if(!cm_allowed($conn,'edit')&&!cm_allowed($conn,'update'))cm_json(false,'No edit permission.');}else{if(!cm_allowed($conn,'create'))cm_json(false,'No create permission.');}
        $name=trim((string)($_POST['customer_name']??''));$mobile=preg_replace('/\D+/','',(string)($_POST['mobile']??''));$alt=preg_replace('/\D+/','',(string)($_POST['alternate_mobile']??''));$email=trim((string)($_POST['email']??''));$address=trim((string)($_POST['address']??''));$city=trim((string)($_POST['city']??''));$state=trim((string)($_POST['state']??''));$pin=preg_replace('/\D+/','',(string)($_POST['pincode']??''));$type=strtolower(trim((string)($_POST['customer_type']??'individual')));$business=trim((string)($_POST['business_name']??''));$gst=strtoupper(trim((string)($_POST['gst_number']??'')));$active=(int)($_POST['is_active']??1)===1?1:0;
        if($name==='')cm_json(false,'Customer Name is required.');if(strlen($name)>150)cm_json(false,'Customer Name is too long.');if(!preg_match('/^\d{10}$/',$mobile))cm_json(false,'Mobile must contain exactly 10 digits.');if($alt!==''&&!preg_match('/^\d{10}$/',$alt))cm_json(false,'Alternate mobile must contain exactly 10 digits.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))cm_json(false,'Enter a valid email.');if(!in_array($type,['individual','business'],true))cm_json(false,'Invalid customer type.');if($type==='business'&&$business==='')cm_json(false,'Business Name is required.');if($pin!==''&&!preg_match('/^\d{6}$/',$pin))cm_json(false,'Pincode must contain exactly 6 digits.');
        $s=$conn->prepare("SELECT id FROM customers WHERE mobile=? AND id<>? LIMIT 1");$s->bind_param('si',$mobile,$id);$s->execute();$dup=$s->get_result()->fetch_assoc();$s->close();if($dup)cm_json(false,'Another customer already uses this mobile number.');
        $uid=(int)($_SESSION['user_id']??0);$alt=$alt?:null;$email=$email?:null;$address=$address?:null;$city=$city?:null;$state=$state?:null;$pin=$pin?:null;$business=$type==='business'&&$business!==''?$business:null;$gst=$type==='business'&&$gst!==''?$gst:null;
        if($id>0){if(!cm_customer($conn,$id))cm_json(false,'Customer not found.');$s=$conn->prepare("UPDATE customers SET customer_name=?,mobile=?,alternate_mobile=?,email=?,address=?,city=?,state=?,pincode=?,customer_type=?,business_name=?,gst_number=?,is_active=?,updated_by=?,updated_at=NOW() WHERE id=?");$s->bind_param('sssssssssssiii',$name,$mobile,$alt,$email,$address,$city,$state,$pin,$type,$business,$gst,$active,$uid,$id);$s->execute();$s->close();cm_json(true,'Customer updated successfully.',['id'=>$id]);}
        $s=$conn->prepare("INSERT INTO customers(customer_name,mobile,alternate_mobile,email,address,city,state,pincode,customer_type,business_name,gst_number,is_active,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");$s->bind_param('sssssssssssii',$name,$mobile,$alt,$email,$address,$city,$state,$pin,$type,$business,$gst,$active,$uid);$s->execute();$new=(int)$s->insert_id;$s->close();cm_json(true,'Customer added successfully.',['id'=>$new]);
    }
    if($action==='toggle_status'){if(!cm_allowed($conn,'edit')&&!cm_allowed($conn,'update'))cm_json(false,'No edit permission.');$id=(int)($_POST['id']??0);$active=(int)($_POST['is_active']??0)===1?1:0;if(!$id||!cm_customer($conn,$id))cm_json(false,'Customer not found.');$uid=(int)($_SESSION['user_id']??0);$s=$conn->prepare("UPDATE customers SET is_active=?,updated_by=?,updated_at=NOW() WHERE id=?");$s->bind_param('iii',$active,$uid,$id);$s->execute();$s->close();cm_json(true,$active?'Customer activated successfully.':'Customer deactivated successfully.');}
    cm_json(false,'Invalid action.');
}catch(Throwable $e){cm_json(false,$e->getMessage());}
