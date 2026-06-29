<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'users.php');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['users_csrf'])) $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['users_csrf'];
$message=''; $messageType='success';

function u_post(string $k,string $d=''): string { return trim((string)($_POST[$k] ?? $d)); }
function u_int($v): int { return (int)filter_var($v,FILTER_SANITIZE_NUMBER_INT); }
function u_redirect(string $q=''): void { header('Location: users.php'.($q!==''?'?'.$q:'')); exit; }
function u_csrf(): void { if(empty($_POST['csrf_token']) || !hash_equals($_SESSION['users_csrf'] ?? '', (string)$_POST['csrf_token'])) die('Invalid CSRF token.'); }
function u_exists_username(mysqli $conn, string $username, int $exceptId = 0): bool {
    if ($exceptId > 0) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $stmt->bind_param('si', $username, $exceptId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$row;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    u_csrf();
    try {
        $action=u_post('action');
        if($action==='save_user') {
            $id=u_int($_POST['id']??0);
            $roleId=u_int($_POST['role_id']??0);
            $name=u_post('name'); $email=u_post('email'); $mobile=u_post('mobile'); $username=u_post('username'); $password=u_post('password');
            $isActive=isset($_POST['is_active'])?1:0;
            if($roleId<=0 || $name==='' || $username==='') throw new RuntimeException('Role, name and username are required.');
            if(u_exists_username($conn, $username, $id)) throw new RuntimeException('Username already exists.');
            if($id>0) {
                if($password!=='') {
                    $hash=password_hash($password,PASSWORD_BCRYPT);
                    $stmt=$conn->prepare("UPDATE users SET role_id=?, name=?, email=?, mobile=?, username=?, password_hash=?, is_active=?, updated_by=?, updated_at=NOW() WHERE id=?");
                    $uid=(int)($_SESSION['user_id']??0); $stmt->bind_param('isssssiii',$roleId,$name,$email,$mobile,$username,$hash,$isActive,$uid,$id);
                } else {
                    $stmt=$conn->prepare("UPDATE users SET role_id=?, name=?, email=?, mobile=?, username=?, is_active=?, updated_by=?, updated_at=NOW() WHERE id=?");
                    $uid=(int)($_SESSION['user_id']??0); $stmt->bind_param('issssiiii',$roleId,$name,$email,$mobile,$username,$isActive,$uid,$id);
                }
                $stmt->execute(); $stmt->close(); u_redirect('msg=updated');
            }
            if($password==='') $password='12345678';
            $hash=password_hash($password,PASSWORD_BCRYPT);
            $uid=(int)($_SESSION['user_id']??0);
            $stmt=$conn->prepare("INSERT INTO users (role_id,name,email,mobile,username,password_hash,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->bind_param('isssssii',$roleId,$name,$email,$mobile,$username,$hash,$isActive,$uid);
            $stmt->execute();
            $newUserId=(int)$stmt->insert_id;
            $stmt->close();
            if($newUserId<=0) throw new RuntimeException('User was not inserted. Please try again.');
            u_redirect('msg=created&uid='.$newUserId);
        }
        if($action==='disable_user') {
            $id=u_int($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid user.');
            $stmt=$conn->prepare("UPDATE users SET is_active=0, updated_by=?, updated_at=NOW() WHERE id=?");
            $uid=(int)($_SESSION['user_id']??0); $stmt->bind_param('ii',$uid,$id); $stmt->execute(); $stmt->close(); u_redirect('msg=disabled');
        }
    } catch(Throwable $e) {
        $_SESSION['users_flash_message']=$e->getMessage();
        $_SESSION['users_flash_type']='danger';
        u_redirect('msg=failed');
    }
}
if(!empty($_SESSION['users_flash_message'])){
    $message=(string)$_SESSION['users_flash_message'];
    $messageType=(string)($_SESSION['users_flash_type'] ?? 'danger');
    unset($_SESSION['users_flash_message'], $_SESSION['users_flash_type']);
}
if(($_GET['msg']??'')==='created') $message='User created successfully.';
elseif(($_GET['msg']??'')==='updated') $message='User updated successfully.';
elseif(($_GET['msg']??'')==='disabled') $message='User disabled successfully.';

$createdUserId=u_int($_GET['uid']??0);
$roles=[]; $rr=$conn->query("SELECT id,role_name,role_key FROM roles WHERE is_active=1 ORDER BY id"); while($r=$rr->fetch_assoc()) $roles[]=$r;
$users=[];
$sql="SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY ".($createdUserId>0 ? "(u.id=".$createdUserId.") DESC," : "")." u.is_active DESC,u.id DESC";
$res=$conn->query($sql); while($row=$res->fetch_assoc()) $users[]=$row;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Users - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .master-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .master-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .master-stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px
    }

    .master-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto
    }

    .master-stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase
    }

    .master-stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main)
    }

    .master-card {
        padding: 24px
    }

    .master-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 18px
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px
    }

    .status-pill.active {
        color: var(--success-color);
        background: color-mix(in srgb, var(--success-color) 14%, transparent)
    }

    .status-pill.inactive {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent)
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

    .small-muted {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700
    }

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

    .recent-created-row {
        background: color-mix(in srgb, var(--success-color, #16a34a) 10%, transparent) !important
    }

    @media(max-width:991px) {
        .master-card {
            padding: 18px
        }

        .master-page .page-head {
            padding: 20px
        }
    }
    </style>

</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>
    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section master-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Users</h1>
                            <p class="text-muted-custom mb-0">Create and manage login users.</p>
                        </div><button class="btn btn-primary rounded-pill px-4 fw-bold" data-bs-toggle="modal"
                            data-bs-target="#userModal" id="newUserBtn">Create User</button>
                    </div>
                </div>
                <?php if($message): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($messageType==='success' ? 'Success' : 'Failed') ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="card-ui master-card">
                    <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
                        <h2 class="master-title mb-0">User List</h2><input type="search" id="tableSearch"
                            class="form-control" style="max-width:340px" placeholder="Search user...">
                    </div>
                    <div class="table-responsive">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Mobile</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$users): ?><tr>
                                    <td colspan="6" class="text-center text-muted-custom py-4">No users found.</td>
                                </tr><?php endif; ?>
                                <?php foreach($users as $u): ?><tr
                                    class="<?= ((int)$createdUserId>0 && (int)$u['id']===(int)$createdUserId) ? 'recent-created-row' : '' ?>">
                                    <td><strong><?=e($u['name'])?></strong><span
                                            class="small-muted"><?=e($u['email'] ?? '')?></span></td>
                                    <td><?=e($u['username'])?></td>
                                    <td><?=e($u['role_name'] ?? '-')?></td>
                                    <td><?=e($u['mobile'] ?? '-')?></td>
                                    <td><span
                                            class="status-pill <?=(int)$u['is_active']===1?'active':'inactive'?>"><?=(int)$u['is_active']===1?'Active':'Inactive'?></span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit"
                                            data-bs-toggle="modal" data-bs-target="#userModal"
                                            data-id="<?=e($u['id'])?>" data-role-id="<?=e($u['role_id'])?>"
                                            data-name="<?=e($u['name'])?>" data-email="<?=e($u['email'])?>"
                                            data-mobile="<?=e($u['mobile'])?>" data-username="<?=e($u['username'])?>"
                                            data-is-active="<?=e($u['is_active'])?>">Edit</button>
                                        <?php if((int)$u['is_active']===1 && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Disable this user?')"><input type="hidden"
                                                name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden"
                                                name="action" value="disable_user"><input type="hidden" name="id"
                                                value="<?=e($u['id'])?>"><button
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">Disable</button>
                                        </form><?php endif; ?>
                                    </td>
                                </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content" id="userForm"><input type="hidden" name="csrf_token"
                    value="<?=e($csrfToken)?>"><input type="hidden" name="action" value="save_user"><input type="hidden"
                    name="id" id="id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">Create User</h5><button type="button"
                        class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-bold">Role *</label><select name="role_id"
                                id="role_id" class="form-select" required>
                                <option value="">Select Role</option><?php foreach($roles as $r): ?><option
                                    value="<?=e($r['id'])?>"><?=e($r['role_name'])?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Name *</label><input name="name"
                                id="name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Email</label><input name="email"
                                id="email" type="email" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Mobile</label><input name="mobile"
                                id="mobile" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Username *</label><input name="username"
                                id="username" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Password</label><input name="password"
                                id="password" type="password" class="form-control"
                                placeholder="Leave blank to keep same"></div>
                        <div class="col-12"><label class="form-check"><input type="checkbox" name="is_active"
                                    id="is_active" value="1" class="form-check-input" checked><span
                                    class="form-check-label fw-bold">Active</span></label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button"
                        class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill px-4 fw-bold"
                        id="submitBtn">Save User</button></div>
            </form>
        </div>
    </div>
    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    (function() {
        const toastEl = document.getElementById('pageToast');
        if (toastEl && window.bootstrap) {
            new bootstrap.Toast(toastEl).show();
        }
        const userForm = document.getElementById('userForm');
        if (userForm) {
            userForm.addEventListener('submit', function() {
                const btn = document.getElementById('submitBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Saving...';
                }
            });
        }
        const fields = ['id', 'role_id', 'name', 'email', 'mobile', 'username', 'password', 'is_active'];

        function set(id, val) {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = String(val) === '1';
            else el.value = val || '';
        }
        document.getElementById('newUserBtn')?.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Create User';
            document.getElementById('submitBtn').textContent = 'Save User';
            fields.forEach(f => set(f, ''));
            set('is_active', '1');
        });
        document.querySelectorAll('.js-edit').forEach(btn => btn.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('submitBtn').textContent = 'Update User';
            set('id', btn.dataset.id);
            set('role_id', btn.dataset.roleId);
            set('name', btn.dataset.name);
            set('email', btn.dataset.email);
            set('mobile', btn.dataset.mobile);
            set('username', btn.dataset.username);
            set('password', '');
            set('is_active', btn.dataset.isActive);
        }));
        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const v = this.value.toLowerCase();
            document.querySelectorAll('#dataTable tbody tr').forEach(r => r.style.display = r.textContent
                .toLowerCase().includes(v) ? '' : 'none');
        });
        if (window.lucide) lucide.createIcons();
    })();
    </script>
</body>

</html>