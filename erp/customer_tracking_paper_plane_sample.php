<?php
/*
 * Subhiksha Cards - Customer Tracking UI Sample
 * Paper Plane Animation
 *
 * SAMPLE ONLY:
 * - No database connection
 * - No existing ERP logic changed
 * - Designed for desktop + mobile
 */

$job = [
    'job_card_no' => 'SC-JOB-260807-0001',
    'customer_name' => 'Srinithi',
    'delivery_date' => '01 Sep 2026',
    'function_name' => 'Bridal Shower',
    'payment_label' => 'Paid',
    'status_label' => 'In Production',
    'progress' => 57,
];

$steps = [
    [
        'name' => 'Enquiry',
        'status' => 'completed',
        'start' => '07 Aug 2026, 12:46 AM',
        'complete' => '07 Aug 2026, 12:46 AM',
        'expected' => '',
    ],
    [
        'name' => 'Sales Order / Proforma Invoice',
        'status' => 'completed',
        'start' => '07 Aug 2026, 12:46 AM',
        'complete' => '07 Aug 2026, 09:55 AM',
        'expected' => '',
    ],
    [
        'name' => 'Proofing',
        'status' => 'completed',
        'start' => '07 Aug 2026, 12:46 AM',
        'complete' => '07 Aug 2026, 09:56 AM',
        'expected' => '',
    ],
    [
        'name' => 'Printing',
        'status' => 'in_progress',
        'start' => '07 Aug 2026, 10:20 AM',
        'complete' => '',
        'expected' => '08 Aug 2026, 06:00 PM',
    ],
    [
        'name' => 'Design Approval',
        'status' => 'pending',
        'start' => '',
        'complete' => '',
        'expected' => '08 Aug 2026, 07:00 PM',
    ],
    [
        'name' => 'Packing',
        'status' => 'pending',
        'start' => '',
        'complete' => '',
        'expected' => '09 Aug 2026, 11:00 AM',
    ],
    [
        'name' => 'Dispatch',
        'status' => 'pending',
        'start' => '',
        'complete' => '',
        'expected' => '09 Aug 2026, 05:00 PM',
    ],
];

$currentIndex = 0;
foreach ($steps as $i => $step) {
    if ($step['status'] === 'in_progress') {
        $currentIndex = $i;
        break;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Subhiksha Cards - Paper Plane Tracking Sample</title>

<style>
:root {
    --brand-blue: #0a438c;
    --brand-blue-2: #0d4f9d;
    --brand-orange: #f47a00;
    --brand-orange-2: #ff9700;

    --green: #16a34a;
    --green-soft: #dcfce7;

    --blue: #2563eb;
    --blue-soft: #eaf2ff;

    --ink: #10213f;
    --muted: #65758f;
    --line: #d9e3ee;
    --soft: #f8fafc;
    --pending: #aab4c0;

    --card: #ffffff;
    --body: #f5f8fc;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    max-width: 100%;
    overflow-x: hidden;
}

body {
    font-family: Inter, Arial, sans-serif;
    background:
        radial-gradient(circle at 20% 0%, rgba(10, 67, 140, .08), transparent 34%),
        linear-gradient(180deg, #f8fbfe 0%, var(--body) 100%);
    color: var(--ink);
}

button,
input {
    font: inherit;
}

.page {
    width: min(100%, 780px);
    margin: 0 auto;
    padding: 14px;
}

/* Header */
.hero {
    position: relative;
    overflow: hidden;
    min-height: 185px;
    padding: 28px 28px 30px;
    border-radius: 30px;
    color: #fff;
    background: linear-gradient(135deg, #0a438c, #083a78 78%);
    box-shadow: 0 18px 46px rgba(10, 67, 140, .20);
}

.hero::after {
    content: "";
    position: absolute;
    width: 260px;
    height: 260px;
    right: -80px;
    top: -120px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, .10);
    box-shadow:
        0 0 0 24px rgba(255,255,255,.025),
        0 0 0 54px rgba(255,255,255,.015);
}

.hero-inner {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 180px minmax(0, 1fr);
    gap: 20px;
    align-items: center;
}

.hero-logo-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 0;
}

.hero-logo {
    width: 160px;
    max-width: 100%;
    height: auto;
    display: block;
    filter: drop-shadow(0 8px 14px rgba(0,0,0,.08));
}

.hero-copy small {
    display: block;
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 8px;
    opacity: .96;
}

.hero-copy h1 {
    margin: 0;
    font-size: clamp(32px, 6vw, 52px);
    line-height: 1.04;
    letter-spacing: -.8px;
}

/* Card base */
.card {
    background: rgba(255,255,255,.97);
    border: 1px solid var(--line);
    border-radius: 22px;
    box-shadow: 0 10px 30px rgba(20, 40, 60, .06);
}

/* Search */
.search-card {
    margin-top: 16px;
    padding: 18px;
}

.search-card label {
    display: block;
    margin-bottom: 8px;
    color: var(--brand-blue);
    font-size: 14px;
    font-weight: 900;
}

.search-row {
    display: grid;
    grid-template-columns: minmax(0,1fr) 110px;
    gap: 10px;
}

.search-row input {
    width: 100%;
    min-width: 0;
    height: 50px;
    border: 1px solid #cfdbe8;
    border-radius: 15px;
    padding: 0 14px;
    background: #f9fbfd;
    color: var(--ink);
    font-size: 16px;
    font-weight: 900;
    outline: none;
}

.search-row input:focus {
    border-color: var(--brand-blue-2);
    box-shadow: 0 0 0 3px rgba(10, 67, 140, .10);
}

.search-row button {
    position: relative;
    overflow: hidden;
    border: 0;
    border-radius: 15px;
    background: linear-gradient(135deg, var(--brand-orange-2), var(--brand-orange));
    color: #fff;
    font-size: 15px;
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 9px 20px rgba(244, 122, 0, .22);
}

.search-row button::before {
    content: "➤";
    display: inline-block;
    margin-right: 6px;
    transform: rotate(-12deg);
}

/* Order summary */
.order-card {
    margin-top: 16px;
    padding: 20px;
}

.order-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.order-head h2 {
    margin: 0;
    min-width: 0;
    font-size: clamp(23px, 5vw, 34px);
    line-height: 1.16;
    color: var(--ink);
    overflow-wrap: anywhere;
}

.badge {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 999px;
    background: var(--green-soft);
    color: #08752d;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}

.badge::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--green);
}

.progress-label {
    margin-top: 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 900;
    color: var(--muted);
}

.progress-value {
    color: var(--brand-blue);
    font-size: 18px;
}

.progress-track {
    height: 10px;
    margin-top: 8px;
    border-radius: 999px;
    background: #e3e9f0;
    overflow: hidden;
}

.progress-fill {
    width: 0;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--brand-orange), var(--brand-orange-2));
    transition: width 1.35s cubic-bezier(.22,.75,.18,1);
}

.current-status {
    margin-top: 14px;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.4;
}

.current-status strong {
    color: var(--brand-orange);
    font-weight: 950;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 10px;
    margin-top: 16px;
}

.info-box {
    min-width: 0;
    padding: 13px;
    border: 1px solid var(--line);
    border-radius: 15px;
    background: var(--soft);
}

.info-box span {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 900;
    margin-bottom: 4px;
}

.info-box strong {
    display: block;
    font-size: 14px;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

/* Timeline */
.timeline-shell {
    margin-top: 16px;
    padding: 12px 0 4px;
    position: relative;
}

.timeline {
    position: relative;
    padding: 12px 0 10px;
}

.route {
    position: absolute;
    left: 38px;
    top: 31px;
    bottom: 35px;
    width: 4px;
    border-radius: 999px;
    background: #d4dce5;
}

.route-complete {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    height: 0;
    border-radius: inherit;
    background: linear-gradient(180deg, var(--green), var(--brand-orange));
    transition: height 1.55s cubic-bezier(.22,.75,.18,1);
}

.route::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 0;
    bottom: 0;
    transform: translateX(-50%);
    border-left: 1px dashed rgba(255,255,255,.36);
}

/* Paper plane */
.paper-plane {
    position: absolute;
    left: 1px;
    bottom: 14px;
    width: 73px;
    height: 60px;
    z-index: 8;
    pointer-events: none;
    transition: transform 1.9s cubic-bezier(.22,.78,.16,1);
}

.paper-plane svg {
    display: block;
    width: 100%;
    height: 100%;
    overflow: visible;
    filter: drop-shadow(0 10px 12px rgba(244,122,0,.18));
}

.plane-float {
    animation: planeFloat 2s ease-in-out infinite;
    transform-origin: 50% 50%;
}

@keyframes planeFloat {
    0%, 100% { transform: translateY(0) rotate(-4deg); }
    50% { transform: translateY(-5px) rotate(2deg); }
}

.paper-trail {
    position: absolute;
    left: 28px;
    bottom: 54px;
    width: 58px;
    height: 130px;
    pointer-events: none;
    opacity: .68;
    transform: rotate(2deg);
}

.paper-trail::before,
.paper-trail::after {
    content: "";
    position: absolute;
    border: 2px dashed rgba(244,122,0,.38);
    border-color: rgba(244,122,0,.38) transparent transparent rgba(244,122,0,.38);
    border-radius: 50%;
}

.paper-trail::before {
    width: 42px;
    height: 82px;
    left: 0;
    bottom: 0;
    transform: rotate(-18deg);
}

.paper-trail::after {
    width: 34px;
    height: 65px;
    left: 18px;
    bottom: 50px;
    transform: rotate(32deg);
}

/* Steps */
.step {
    position: relative;
    margin-left: 78px;
    margin-bottom: 10px;
    opacity: 0;
    transform: translateY(10px);
    animation: stepAppear .5s ease forwards;
}

@keyframes stepAppear {
    to {
        opacity: 1;
        transform: none;
    }
}

.node {
    position: absolute;
    left: -54px;
    top: 16px;
    z-index: 4;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #fff;
    border: 4px solid var(--pending);
    color: var(--pending);
    font-size: 13px;
    font-weight: 950;
}

.step.completed .node {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}

.step.in_progress .node {
    border-color: var(--brand-orange);
    color: var(--brand-orange);
    box-shadow:
        0 0 0 7px rgba(244,122,0,.09),
        0 0 22px rgba(244,122,0,.20);
    animation: activeNodePulse 1.9s ease-in-out infinite;
}

.step.in_progress .node::after {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--brand-orange);
}

@keyframes activeNodePulse {
    0%,100% {
        box-shadow:
            0 0 0 6px rgba(244,122,0,.08),
            0 0 12px rgba(244,122,0,.16);
    }
    50% {
        box-shadow:
            0 0 0 12px rgba(244,122,0,.03),
            0 0 28px rgba(244,122,0,.30);
    }
}

.step-card {
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 7px 20px rgba(20,40,60,.045);
}

.step.in_progress .step-card {
    border-color: #ffd3aa;
    background: linear-gradient(90deg, #fff7ef 0%, #fff 72%);
    box-shadow: 0 9px 25px rgba(244,122,0,.10);
}

.step-button {
    width: 100%;
    border: 0;
    background: transparent;
    padding: 13px 14px;
    display: grid;
    grid-template-columns: minmax(0,1fr) auto 18px;
    gap: 8px;
    align-items: center;
    text-align: left;
    cursor: pointer;
}

.step-title {
    color: var(--ink);
    font-size: 15px;
    font-weight: 950;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.step.in_progress .step-title {
    color: var(--brand-blue);
}

.step-meta {
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 800;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.status {
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 900;
    white-space: nowrap;
}

.status.completed {
    background: var(--green-soft);
    color: #08752d;
}

.status.in_progress {
    background: #fff0e2;
    color: #d45f00;
}

.status.pending {
    background: #f0f3f6;
    color: #5d6c7d;
}

.arrow {
    color: var(--muted);
    font-size: 17px;
    transition: transform .25s ease;
}

.step.open .arrow {
    transform: rotate(180deg);
}

/* Expand details */
.details {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows .25s ease;
}

.details > div {
    overflow: hidden;
}

.step.open .details {
    grid-template-rows: 1fr;
}

.detail-inner {
    padding: 0 14px;
}

.step.open .detail-inner {
    padding-bottom: 13px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 8px;
    padding-top: 10px;
    border-top: 1px solid #edf1f5;
}

.detail-box {
    min-width: 0;
    padding: 10px;
    border: 1px solid #e5ebf2;
    border-radius: 12px;
    background: #f9fbfd;
}

.detail-box small {
    display: block;
    color: var(--muted);
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.detail-box strong {
    display: block;
    font-size: 11px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.footer-note {
    margin-top: 14px;
    padding: 14px 16px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.5;
}

/* Mobile */
@media (max-width: 600px) {
    .page {
        padding: 8px;
    }

    .hero {
        min-height: 150px;
        padding: 20px 16px 22px;
        border-radius: 22px;
    }

    .hero-inner {
        grid-template-columns: 90px minmax(0,1fr);
        gap: 12px;
    }

    .hero-logo {
        width: 86px;
    }

    .hero-copy small {
        font-size: 11px;
        line-height: 1.3;
    }

    .hero-copy h1 {
        font-size: clamp(26px, 8vw, 37px);
    }

    .search-card,
    .order-card {
        padding: 13px;
        border-radius: 18px;
    }

    .search-row {
        grid-template-columns: minmax(0,1fr) 76px;
        gap: 7px;
    }

    .search-row input {
        height: 44px;
        font-size: 13px;
    }

    .search-row button {
        height: 44px;
        font-size: 13px;
    }

    .search-row button::before {
        display: none;
    }

    .order-head {
        align-items: flex-start;
    }

    .order-head h2 {
        font-size: clamp(20px, 6.5vw, 27px);
    }

    .badge {
        padding: 6px 8px;
        font-size: 9px;
    }

    .info-grid {
        grid-template-columns: repeat(2,minmax(0,1fr));
        gap: 7px;
    }

    .info-box {
        padding: 10px;
    }

    .timeline {
        padding-top: 8px;
    }

    .route {
        left: 26px;
        top: 28px;
    }

    .step {
        margin-left: 58px;
        margin-bottom: 8px;
    }

    .node {
        left: -45px;
        top: 14px;
        width: 26px;
        height: 26px;
        border-width: 3px;
    }

    .step-button {
        padding: 11px 10px;
        grid-template-columns: minmax(0,1fr) auto 16px;
    }

    .step-title {
        font-size: 13.5px;
    }

    .step-meta {
        font-size: 10px;
    }

    .status {
        font-size: 9px;
        padding: 5px 7px;
    }

    .paper-plane {
        left: -7px;
        width: 58px;
        height: 49px;
    }

    .paper-trail {
        left: 16px;
        width: 45px;
        height: 105px;
    }
}

@media (max-width: 360px) {
    .hero-inner {
        grid-template-columns: 1fr;
    }

    .hero-logo-wrap {
        justify-content: flex-start;
    }

    .hero-logo {
        width: 94px;
    }

    .search-row {
        grid-template-columns: 1fr;
    }

    .search-row button {
        width: 100%;
    }

    .info-grid,
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .step-button {
        grid-template-columns: minmax(0,1fr) 16px;
    }

    .status {
        grid-column: 1;
        justify-self: start;
        margin-top: 2px;
    }
}
</style>
</head>

<body>
<div class="page">

    <section class="hero">
        <div class="hero-inner">
            <div class="hero-logo-wrap">
                <?php if (is_file(__DIR__ . '/assets/subhiksha-logo.png')): ?>
                    <img src="assets/subhiksha-logo.png" alt="Subhiksha Cards" class="hero-logo">
                <?php endif; ?>
            </div>

            <div class="hero-copy">
                <small>Subhiksha Cards Customer Portal</small>
                <h1>Track Your Invitation Order</h1>
            </div>
        </div>
    </section>

    <section class="search-card card">
        <label for="job_card_no">Enter Job Card Number</label>
        <div class="search-row">
            <input
                type="text"
                id="job_card_no"
                value="<?= h($job['job_card_no']) ?>"
                autocomplete="off"
            >
            <button type="button">Track</button>
        </div>
    </section>

    <section class="order-card card">
        <div class="order-head">
            <h2>Order #<?= h($job['job_card_no']) ?></h2>
            <span class="badge"><?= h($job['status_label']) ?></span>
        </div>

        <div class="progress-label">
            <span>Overall Progress</span>
            <span class="progress-value"><span id="progressNumber">0</span>%</span>
        </div>

        <div class="progress-track">
            <div class="progress-fill" id="progressFill"></div>
        </div>

        <div class="current-status">
            Current Status:
            <strong><?= h($steps[$currentIndex]['name'] ?? '-') ?></strong>
        </div>

        <div class="info-grid">
            <div class="info-box">
                <span>Customer</span>
                <strong><?= h($job['customer_name']) ?></strong>
            </div>

            <div class="info-box">
                <span>Delivery</span>
                <strong><?= h($job['delivery_date']) ?></strong>
            </div>

            <div class="info-box">
                <span>Function</span>
                <strong><?= h($job['function_name']) ?></strong>
            </div>

            <div class="info-box">
                <span>Payment</span>
                <strong><?= h($job['payment_label']) ?></strong>
            </div>
        </div>
    </section>

    <section class="timeline-shell">
        <div class="timeline" id="timeline">
            <div class="route">
                <div class="route-complete" id="routeComplete"></div>
            </div>

            <div class="paper-trail" id="paperTrail"></div>

            <div class="paper-plane" id="paperPlane" aria-hidden="true">
                <div class="plane-float">
                    <svg viewBox="0 0 90 70">
                        <defs>
                            <linearGradient id="planeOrange" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#ffae24"/>
                                <stop offset="55%" stop-color="#f47a00"/>
                                <stop offset="100%" stop-color="#d85c00"/>
                            </linearGradient>
                            <linearGradient id="planeFold" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#ffd38b"/>
                                <stop offset="100%" stop-color="#f47a00"/>
                            </linearGradient>
                        </defs>

                        <path
                            d="M5 34 L83 5 L58 61 L40 45 L28 58 L31 40 Z"
                            fill="url(#planeOrange)"
                        />

                        <path
                            d="M31 40 L83 5 L40 45 Z"
                            fill="#ffbf56"
                            opacity=".95"
                        />

                        <path
                            d="M40 45 L58 61 L48 43 Z"
                            fill="url(#planeFold)"
                        />

                        <path
                            d="M31 40 L28 58 L40 45 Z"
                            fill="#db6200"
                            opacity=".9"
                        />
                    </svg>
                </div>
            </div>

            <?php foreach ($steps as $index => $step): ?>
                <?php
                $status = (string)$step['status'];

                if ($status === 'completed') {
                    $meta = 'Start: ' . $step['start'] . ' · Completed: ' . $step['complete'];
                    $statusLabel = 'Completed';
                } elseif ($status === 'in_progress') {
                    $meta = 'Start: ' . $step['start'] . ' · Expected: ' . $step['expected'];
                    $statusLabel = 'In Progress';
                } else {
                    $meta = 'Expected: ' . $step['expected'];
                    $statusLabel = 'Pending';
                }
                ?>

                <article
                    class="step <?= h($status) ?>"
                    data-index="<?= (int)$index ?>"
                    style="animation-delay: <?= number_format($index * 0.07, 2, '.', '') ?>s"
                >
                    <span class="node">
                        <?= $status === 'completed' ? '✓' : '' ?>
                    </span>

                    <div class="step-card">
                        <button
                            type="button"
                            class="step-button"
                            aria-expanded="false"
                        >
                            <div>
                                <div class="step-title"><?= h($step['name']) ?></div>
                                <div class="step-meta"><?= h($meta) ?></div>
                            </div>

                            <span class="status <?= h($status) ?>">
                                <?= h($statusLabel) ?>
                            </span>

                            <span class="arrow">⌄</span>
                        </button>

                        <div class="details">
                            <div>
                                <div class="detail-inner">
                                    <div class="detail-grid">
                                        <div class="detail-box">
                                            <small>Stage</small>
                                            <strong><?= h($step['name']) ?></strong>
                                        </div>

                                        <div class="detail-box">
                                            <small>Status</small>
                                            <strong><?= h($statusLabel) ?></strong>
                                        </div>

                                        <div class="detail-box">
                                            <small>Start</small>
                                            <strong><?= h($step['start'] ?: '-') ?></strong>
                                        </div>

                                        <div class="detail-box">
                                            <small>Expected / Completed</small>
                                            <strong><?= h($step['expected'] ?: ($step['complete'] ?: '-')) ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="footer-note card">
        We'll notify you as your order moves forward.<br>
        Thank you for choosing <strong>Subhiksha Cards</strong>.
    </div>

</div>

<script>
const TRACKING = {
    progress: <?= (int)$job['progress'] ?>,
    currentIndex: <?= (int)$currentIndex ?>
};

const progressFill = document.getElementById('progressFill');
const progressNumber = document.getElementById('progressNumber');
const timeline = document.getElementById('timeline');
const routeComplete = document.getElementById('routeComplete');
const paperPlane = document.getElementById('paperPlane');
const steps = Array.from(document.querySelectorAll('.step'));

let progressTimer = null;
let resizeTimer = null;

function setProgressAnimation() {
    if (progressTimer) {
        clearInterval(progressTimer);
    }

    progressFill.style.width = TRACKING.progress + '%';

    let current = 0;
    progressNumber.textContent = '0';

    progressTimer = setInterval(function () {
        current += 1;

        if (current >= TRACKING.progress) {
            current = TRACKING.progress;
            clearInterval(progressTimer);
            progressTimer = null;
        }

        progressNumber.textContent = current;
    }, 20);
}

function positionPaperPlane(animate = true) {
    const currentStep = steps[TRACKING.currentIndex];

    if (!currentStep || !timeline || !paperPlane) {
        return;
    }

    /*
     * IMPORTANT:
     * Plane visually STARTS from the BOTTOM of the timeline.
     * It then moves UP and stops beside the current stage.
     */
    const planeHeight = paperPlane.offsetHeight || 60;
    const bottomPadding = 14;

    const startTop =
        timeline.offsetHeight
        - planeHeight
        - bottomPadding;

    const currentCenter =
        currentStep.offsetTop
        + Math.round(currentStep.offsetHeight / 2);

    const targetTop =
        Math.max(
            0,
            currentCenter - Math.round(planeHeight / 2)
        );

    paperPlane.style.transition = animate
        ? 'transform 1.9s cubic-bezier(.22,.78,.16,1)'
        : 'none';

    paperPlane.style.bottom = 'auto';
    paperPlane.style.top = startTop + 'px';
    paperPlane.style.transform = 'translateY(0)';

    const routeTop = 31;
    const fillHeight = Math.max(
        0,
        currentCenter - routeTop
    );

    requestAnimationFrame(function () {
        routeComplete.style.height = fillHeight + 'px';

        requestAnimationFrame(function () {
            paperPlane.style.transform =
                'translateY(' + (targetTop - startTop) + 'px)';
        });
    });
}

function startTrackingAnimation() {
    setProgressAnimation();
    positionPaperPlane(true);
}

window.addEventListener('load', startTrackingAnimation);

window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);

    resizeTimer = setTimeout(function () {
        positionPaperPlane(false);

        requestAnimationFrame(function () {
            positionPaperPlane(true);
        });
    }, 120);
});

document.querySelectorAll('.step-button').forEach(function (button) {
    button.addEventListener('click', function () {
        const step = button.closest('.step');

        if (!step) {
            return;
        }

        const isOpen = step.classList.toggle('open');

        button.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );

        setTimeout(function () {
            positionPaperPlane(false);
        }, 280);
    });
});
</script>
</body>
</html>
