<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function ctTableExists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function ctDate($value): string
{
    return !empty($value) ? date('d M Y', strtotime((string)$value)) : '-';
}

function ctDateTime($value): string
{
    return !empty($value) ? date('d M Y, h:i A', strtotime((string)$value)) : '-';
}

function ctMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function ctStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'completed' || $status === 'skipped') return 'done';
    if ($status === 'in_progress') return 'live';
    if ($status === 'delayed') return 'delay';
    if ($status === 'cancelled') return 'cancel';
    return 'pending';
}

function ctStatusLabel(string $status): string
{
    $status = strtolower(trim($status));
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'pending'));
}

$token = trim((string)($_GET['token'] ?? ''));
$message = '';
$messageType = 'danger';
$job = null;
$steps = [];

if ($token === '') {
    $message = 'Invalid tracking link.';
} elseif (!ctTableExists($conn, 'job_cards') || !ctTableExists($conn, 'job_tracking')) {
    $message = 'Tracking system is not available.';
} else {
    try {
        $sql = "
            SELECT
                jc.*,
                pb.proforma_no,
                ft.function_name,
                pt.printing_name,
                pst.sub_type_name,
                jcs.status_name,
                jcs.status_key,
                ctl.expires_at AS tracking_expires_at,
                ctl.is_active AS tracking_is_active
            FROM job_cards jc
            LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id
            LEFT JOIN function_types ft ON ft.id = jc.function_type_id
            LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            LEFT JOIN customer_tracking_links ctl ON ctl.job_card_id = jc.id AND ctl.tracking_token = ?
            WHERE jc.tracking_token = ?
               OR ctl.tracking_token = ?
            ORDER BY jc.id DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $token, $token, $token);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            $message = 'Tracking link not found.';
        } elseif (isset($job['tracking_is_active']) && $job['tracking_is_active'] !== null && (int)$job['tracking_is_active'] !== 1) {
            $message = 'This tracking link is inactive.';
            $job = null;
        } elseif (!empty($job['tracking_expires_at']) && strtotime((string)$job['tracking_expires_at']) < time()) {
            $message = 'This tracking link has expired.';
            $job = null;
        }
    } catch (Throwable $e) {
        $message = 'Unable to load tracking details.';
        $job = null;
    }
}

if ($job) {
    try {
        $stmt = $conn->prepare("
            SELECT
                jt.*,
                ws.step_name,
                ws.step_key,
                ws.sort_order,
                rr.role_name,
                ru.username AS responsible_user_name,
                cu.username AS completed_by_name,
                dr.reason_name AS delay_reason_name
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            LEFT JOIN roles rr ON rr.id = jt.responsible_role_id
            LEFT JOIN users ru ON ru.id = jt.responsible_user_id
            LEFT JOIN users cu ON cu.id = jt.completed_by
            LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
            WHERE jt.job_card_id = ?
            ORDER BY ws.sort_order ASC, jt.id ASC
        ");
        $jobId = (int)$job['id'];
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $steps[] = $row;
        $stmt->close();
    } catch (Throwable $e) {
        $steps = [];
    }
}

$totalSteps = count($steps);
$completedSteps = 0;
$liveStepName = '-';
foreach ($steps as $s) {
    $status = strtolower((string)($s['status'] ?? 'pending'));
    if (in_array($status, ['completed', 'skipped'], true)) $completedSteps++;
    if ($liveStepName === '-' && in_array($status, ['in_progress', 'delayed'], true)) {
        $liveStepName = (string)($s['step_name'] ?? '-');
    }
}
$progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
$progressPercent = max(0, min(100, $progressPercent));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Order Tracking - Subhiksha Cards</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root{--ink:#0f172a;--muted:#64748b;--line:#dbeafe;--green:#16a34a;--blue:#2563eb;--orange:#f59e0b;--red:#dc2626;--purple:#7c3aed;--pink:#db2777;--sky:#0284c7;--card:#fff}
        *{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:radial-gradient(circle at top left,#dbeafe 0,#f8fafc 30%,#fff7ed 65%,#fdf2f8 100%);color:var(--ink)}
        .page{max-width:1160px;margin:0 auto;padding:18px}.hero{position:relative;overflow:hidden;border-radius:30px;padding:26px;background:linear-gradient(135deg,#0f172a,#1d4ed8 52%,#7c3aed);color:#fff;box-shadow:0 24px 60px rgba(15,23,42,.18)}
        .hero:before,.hero:after{content:"";position:absolute;border-radius:999px;filter:blur(1px);opacity:.24}.hero:before{width:240px;height:240px;background:#22c55e;right:-55px;top:-80px}.hero:after{width:200px;height:200px;background:#f59e0b;left:-80px;bottom:-85px}
        .hero-content{position:relative;z-index:2}.brand-pill{display:inline-flex;gap:8px;align-items:center;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:8px 13px;font-weight:900;font-size:12px;letter-spacing:.04em;text-transform:uppercase}.title{font-size:34px;font-weight:900;margin:12px 0 6px;line-height:1.1}.subtitle{font-weight:700;color:rgba(255,255,255,.82);margin:0}.hero-card{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:22px;padding:16px;backdrop-filter:blur(8px)}.hero-card small{display:block;text-transform:uppercase;font-size:10px;font-weight:900;color:rgba(255,255,255,.7)}.hero-card strong{font-size:21px;font-weight:900}.progress-shell{height:14px;border-radius:999px;background:rgba(255,255,255,.28);overflow:hidden}.progress-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#22c55e,#a3e635,#facc15);box-shadow:0 0 18px rgba(34,197,94,.42)}
        .color-card{border:0;border-radius:24px;padding:18px;background:#fff;box-shadow:0 18px 45px rgba(15,23,42,.08);height:100%;position:relative;overflow:hidden}.color-card:after{content:"";position:absolute;right:-40px;bottom:-40px;width:120px;height:120px;border-radius:999px;opacity:.12}.color-card.blue:after{background:#2563eb}.color-card.green:after{background:#16a34a}.color-card.orange:after{background:#f59e0b}.color-card.purple:after{background:#7c3aed}.color-card small{display:block;font-size:11px;text-transform:uppercase;font-weight:900;color:var(--muted);margin-bottom:6px}.color-card strong,.color-card span{font-weight:900;word-break:break-word;position:relative;z-index:2}.color-icon{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;font-weight:900;margin-bottom:10px}.blue .color-icon{background:linear-gradient(135deg,#2563eb,#38bdf8)}.green .color-icon{background:linear-gradient(135deg,#16a34a,#86efac)}.orange .color-icon{background:linear-gradient(135deg,#f59e0b,#f97316)}.purple .color-icon{background:linear-gradient(135deg,#7c3aed,#db2777)}
        .tracker-wrap{margin-top:18px;background:rgba(255,255,255,.76);border:1px solid rgba(148,163,184,.24);border-radius:30px;padding:22px;box-shadow:0 24px 70px rgba(15,23,42,.10);backdrop-filter:blur(8px)}.section-title{font-size:22px;font-weight:900;margin:0}.section-sub{color:var(--muted);font-weight:700;margin:3px 0 0}.shipment{position:relative;display:grid;gap:18px;margin-top:22px}.shipment:before{content:"";position:absolute;left:28px;top:18px;bottom:18px;width:5px;border-radius:999px;background:linear-gradient(180deg,#22c55e,#38bdf8,#f59e0b,#ef4444);opacity:.24}.step{position:relative;display:grid;grid-template-columns:62px 1fr;gap:14px;align-items:start}.dot{width:62px;height:62px;border-radius:22px;display:grid;place-items:center;font-size:24px;font-weight:900;color:#fff;box-shadow:0 16px 30px rgba(15,23,42,.18);z-index:2}.dot.done{background:linear-gradient(135deg,#16a34a,#22c55e)}.dot.live{background:linear-gradient(135deg,#2563eb,#38bdf8);animation:pulseBlue 1.25s infinite}.dot.delay{background:linear-gradient(135deg,#dc2626,#f97316);animation:pulseRed 1.25s infinite}.dot.pending{background:linear-gradient(135deg,#94a3b8,#64748b)}.dot.cancel{background:linear-gradient(135deg,#991b1b,#ef4444)}@keyframes pulseBlue{0%{box-shadow:0 0 0 0 rgba(37,99,235,.36)}70%{box-shadow:0 0 0 16px rgba(37,99,235,0)}100%{box-shadow:0 0 0 0 rgba(37,99,235,0)}}@keyframes pulseRed{0%{box-shadow:0 0 0 0 rgba(220,38,38,.32)}70%{box-shadow:0 0 0 16px rgba(220,38,38,0)}100%{box-shadow:0 0 0 0 rgba(220,38,38,0)}}
        .step-card{border-radius:24px;background:#fff;border:1px solid rgba(148,163,184,.25);padding:16px;box-shadow:0 12px 35px rgba(15,23,42,.07);position:relative;overflow:hidden}.step-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:7px;background:#94a3b8}.step-card.done:before{background:#22c55e}.step-card.live:before{background:#2563eb}.step-card.delay:before{background:#dc2626}.step-card.cancel:before{background:#991b1b}.step-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.step-title{font-size:17px;font-weight:900;margin:0}.step-meta{font-size:12px;color:var(--muted);font-weight:800;margin-top:4px}.badge-status{border-radius:999px;padding:7px 11px;font-size:11px;font-weight:900;text-transform:uppercase}.badge-status.done{background:#dcfce7;color:#166534}.badge-status.live{background:#dbeafe;color:#1d4ed8}.badge-status.delay{background:#fee2e2;color:#991b1b}.badge-status.pending{background:#f1f5f9;color:#475569}.badge-status.cancel{background:#fee2e2;color:#7f1d1d}.info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.mini{border-radius:16px;padding:10px 12px;background:linear-gradient(135deg,#f8fafc,#ffffff);border:1px solid #e2e8f0}.mini small{display:block;font-size:9px;text-transform:uppercase;font-weight:900;color:var(--muted);margin-bottom:3px}.mini strong,.mini span{font-size:12px;font-weight:900;word-break:break-word}.delay-note{margin-top:12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:18px;padding:11px 13px;font-size:12px;font-weight:900}.empty{border-radius:24px;background:#fff;border:1px solid #e2e8f0;padding:24px;text-align:center;font-weight:900;color:var(--muted)}.footer-note{text-align:center;color:#64748b;font-weight:800;font-size:12px;margin:18px 0 0}.bad-link{background:#fff;border:1px solid #fecaca;color:#991b1b;border-radius:24px;padding:22px;font-weight:900;box-shadow:0 18px 45px rgba(220,38,38,.08)}
        @media(max-width:767px){.page{padding:12px}.hero{border-radius:22px;padding:20px}.title{font-size:26px}.tracker-wrap{padding:14px;border-radius:22px}.step{grid-template-columns:48px 1fr;gap:10px}.shipment:before{left:22px}.dot{width:48px;height:48px;border-radius:16px;font-size:18px}.step-head{flex-direction:column}.info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.color-card{border-radius:20px}.step-card{border-radius:20px;padding:14px}}
    </style>
</head>
<body>
<div class="page">
    <?php if (!$job): ?>
        <div class="bad-link mt-3"><?= e($message ?: 'Tracking details not found.') ?></div>
    <?php else: ?>
        <section class="hero">
            <div class="hero-content">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-7">
                        <span class="brand-pill">Subhiksha Cards Live Tracking</span>
                        <h1 class="title">Your order is moving stage by stage</h1>
                        <p class="subtitle">Track your job card like shipment tracking with live production updates.</p>
                    </div>
                    <div class="col-lg-5">
                        <div class="hero-card">
                            <div class="d-flex justify-content-between gap-3 mb-2">
                                <div><small>Job Card</small><strong><?= e($job['job_card_no'] ?? '-') ?></strong></div>
                                <div class="text-end"><small>Progress</small><strong><?= (int)$progressPercent ?>%</strong></div>
                            </div>
                            <div class="progress-shell"><div class="progress-fill" style="width:<?= (int)$progressPercent ?>%"></div></div>
                            <div class="mt-2 small fw-bold text-white-50">Current Stage: <?= e($liveStepName) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-3 mt-2">
            <div class="col-md-3 col-6"><div class="color-card blue"><div class="color-icon">#</div><small>Proforma No</small><strong><?= e($job['proforma_no'] ?? '-') ?></strong></div></div>
            <div class="col-md-3 col-6"><div class="color-card green"><div class="color-icon">✓</div><small>Customer</small><strong><?= e($job['customer_name'] ?? '-') ?></strong></div></div>
            <div class="col-md-3 col-6"><div class="color-card orange"><div class="color-icon">⏱</div><small>Delivery Date</small><strong><?= e(ctDate($job['delivery_date'] ?? null)) ?></strong></div></div>
            <div class="col-md-3 col-6"><div class="color-card purple"><div class="color-icon">₹</div><small>Balance</small><strong><?= e(ctMoney($job['balance_amount'] ?? 0)) ?></strong></div></div>
            <div class="col-md-4"><div class="color-card blue"><div class="color-icon">P</div><small>Product</small><strong><?= e($job['product_name'] ?? '-') ?></strong></div></div>
            <div class="col-md-4"><div class="color-card green"><div class="color-icon">F</div><small>Function / Type</small><strong><?= e($job['function_name'] ?? '-') ?></strong></div></div>
            <div class="col-md-4"><div class="color-card orange"><div class="color-icon">🖨</div><small>Printing</small><strong><?= e($job['printing_name'] ?? '-') ?></strong><span class="d-block text-muted small fw-bold mt-1"><?= e($job['sub_type_name'] ?? '') ?></span></div></div>
        </div>

        <section class="tracker-wrap">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-end">
                <div>
                    <h2 class="section-title">Production Journey</h2>
                    <p class="section-sub">Every stage is shown one by one with planned date, expected date, live status, and completion time.</p>
                </div>
                <div class="badge-status <?= e(ctStatusClass($job['status_key'] ?? 'pending')) ?>"><?= e($job['status_name'] ?? 'Status') ?></div>
            </div>

            <?php if (!$steps): ?>
                <div class="empty mt-3">No tracking stages found for this job card.</div>
            <?php else: ?>
                <div class="shipment">
                    <?php foreach ($steps as $index => $step): ?>
                        <?php
                            $status = strtolower((string)($step['status'] ?? 'pending'));
                            $class = ctStatusClass($status);
                            $icon = $class === 'done' ? '✓' : ($class === 'live' ? '●' : ($class === 'delay' ? '!' : ($class === 'cancel' ? '×' : $index + 1)));
                        ?>
                        <article class="step">
                            <div class="dot <?= e($class) ?>"><?= e($icon) ?></div>
                            <div class="step-card <?= e($class) ?>">
                                <div class="step-head">
                                    <div>
                                        <h3 class="step-title"><?= e($step['step_name'] ?? '-') ?></h3>
                                        <div class="step-meta">Department: <?= e($step['role_name'] ?? '-') ?><?= !empty($step['responsible_user_name']) ? ' | User: ' . e($step['responsible_user_name']) : '' ?></div>
                                    </div>
                                    <span class="badge-status <?= e($class) ?>"><?= e(ctStatusLabel($status)) ?></span>
                                </div>

                                <div class="info-grid">
                                    <div class="mini"><small>Planned Start</small><strong><?= e(ctDate($step['planned_start_date'] ?? null)) ?></strong></div>
                                    <div class="mini"><small>Expected Completion</small><strong><?= e(ctDate($step['planned_completion_date'] ?? null)) ?></strong></div>
                                    <div class="mini"><small>Actual Start</small><strong><?= e(ctDateTime($step['actual_start_at'] ?? null)) ?></strong></div>
                                    <div class="mini"><small>Completed At</small><strong><?= e(ctDateTime($step['actual_completed_at'] ?? null)) ?></strong></div>
                                </div>

                                <?php if ($status === 'delayed' || (int)($step['is_delayed'] ?? 0) === 1): ?>
                                    <div class="delay-note">
                                        Delay Alert: <?= e($step['delay_reason_name'] ?? 'Reason not updated') ?>
                                        <?= !empty($step['delay_days']) ? ' | Delay Days: ' . e($step['delay_days']) : '' ?>
                                        <?= !empty($step['delay_remarks']) ? ' | Remark: ' . e($step['delay_remarks']) : '' ?>
                                    </div>
                                <?php elseif (!empty($step['remarks'])): ?>
                                    <div class="delay-note" style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8">
                                        Update: <?= e($step['remarks']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <p class="footer-note">This is a live customer tracking page from Subhiksha Cards. Please contact the team for urgent changes.</p>
    <?php endif; ?>
</div>
</body>
</html>
