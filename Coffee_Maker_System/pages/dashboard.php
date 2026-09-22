<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if unauthenticated
if (!isset($_SESSION["admin"]) && !isset($_SESSION["username"])) {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

/* =========================================================
   USER & SESSION DATA
   ========================================================= */
$user_role = $_SESSION["role"] ?? "admin";
$user_name = $_SESSION["username"] ?? $_SESSION["admin"] ?? "User";
$admin_name = $user_name;

// Fetch user contact/mobile number
$user_phone = "N/A";
$logged_username = $_SESSION["username"] ?? $_SESSION["admin"] ?? null;
if ($logged_username) {
    $u_stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    if ($u_stmt) {
        $u_stmt->bind_param("s", $logged_username);
        $u_stmt->execute();
        $u_res = $u_stmt->get_result();
        if ($u_row = $u_res->fetch_assoc()) {
            $user_phone = $u_row["phone"] ?? $u_row["mobile"] ?? $u_row["contact_number"] ?? $u_row["phone_number"] ?? "N/A";
        }
        $u_stmt->close();
    }
}

/* =========================================================
   DASHBOARD DATA
   ========================================================= */

// TOTAL ORDERS
$total_orders = 0;
$result = $conn->query("SELECT COUNT(*) AS total FROM orders");
if ($result) {
    $row = $result->fetch_assoc();
    $total_orders = $row["total"];
}

// TODAY'S SALES
$today_sales = 0;
$result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) AS total
    FROM orders
    WHERE DATE(created_at) = CURDATE()
    AND status != 'Cancelled'
");
if ($result) {
    $row = $result->fetch_assoc();
    $today_sales = $row["total"];
}

// TOTAL PRODUCTS
$total_products = 0;
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM products
    WHERE status != 'Inactive'
");
if ($result) {
    $row = $result->fetch_assoc();
    $total_products = $row["total"];
}

/* =========================================================
   RECENT TRANSACTIONS
   ========================================================= */

$transactions = [];
$result = $conn->query("
    SELECT
        order_number,
        customer_name,
        total_amount,
        payment_method,
        status,
        created_at
    FROM orders
    ORDER BY created_at DESC
    LIMIT 5
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
}

/* =========================================================
   TOP SELLING PRODUCTS
   ========================================================= */

$top_products = [];
$result = $conn->query("
    SELECT
        p.product_name,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.subtotal) AS total_sales
    FROM order_items oi
    INNER JOIN products p
        ON oi.product_id = p.id
    INNER JOIN orders o
        ON oi.order_id = o.id
    WHERE o.status != 'Cancelled'
    GROUP BY p.id, p.product_name
    ORDER BY total_quantity DESC
    LIMIT 3
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $top_products[] = $row;
    }
}

/* =========================================================
   SALES OVERVIEW - LAST 7 DAYS
   ========================================================= */

$sales_data = [];
$result = $conn->query("
    SELECT
        DATE(created_at) AS sale_date,
        COALESCE(SUM(total_amount), 0) AS total_sales
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    AND status != 'Cancelled'
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_data[$row["sale_date"]] = $row["total_sales"];
    }
}

/* =========================================================
   CHART DATA
   ========================================================= */

$chart_days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date("Y-m-d", strtotime("-$i days"));
    $chart_days[] = [
        "date" => $date,
        "label" => date("M d", strtotime($date)),
        "sales" => $sales_data[$date] ?? 0
    ];
}

$max_sales = 0;
foreach ($chart_days as $day) {
    if ($day["sales"] > $max_sales) {
        $max_sales = $day["sales"];
    }
}
if ($max_sales <= 0) {
    $max_sales = 1;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Coffee Maker Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* =========================================================
   RESET & LAYOUT
   ========================================================= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    background: #f5f6f8;
    color: #20242b;
}

.page-label {
    display: none;
}

.app-shell {
    min-height: 100vh;
    display: flex;
}

/* =========================================================
   SIDEBAR STYLES (MATCHED EXACTLY TO ORDERS SIDEBAR)
   ========================================================= */
.sidebar {
    width: 245px;
    min-height: 100vh;
    padding: 30px 18px 20px;
    background: #2B1610;
    color: #fff;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    font-family: Arial, Helvetica, sans-serif;
}

.brand {
    padding: 0 16px 35px;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 1px;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-item {
    padding: 14px 16px;
    color: #aeb3bd;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .5px;
    transition: .2s;
}

.nav-item:hover, .nav-item.active {
    background: #74473b;
    color: #fff;
}

.sidebar-footer { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    border-top: 1px solid #492c25; 
    padding: 18px 10px 0; 
}

.user-wrapper { 
    position: relative; 
    flex: 1; 
}

.user { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    color: #dfe2e8; 
    font-size: 12px; 
    font-weight: 600; 
    cursor: pointer; 
    padding: 6px 8px; 
    border-radius: 6px; 
    transition: background 0.2s; 
    user-select: none; 
}

.user:hover { 
    background: #3c2018; 
}

.avatar { 
    width: 34px; 
    height: 34px; 
    border-radius: 50%; 
    background: #60463e; 
    display: grid; 
    place-items: center; 
    font-size: 14px; 
    color: #fff; 
    flex-shrink: 0; 
}

.user-details-text { 
    display: flex; 
    flex-direction: column; 
    line-height: 1.25; 
}

.user-name { 
    color: #fff; 
    font-size: 13px; 
    font-weight: 600; 
}

.user-role { 
    color: #aeb3bd; 
    font-size: 10px; 
}

.toggle-icon { 
    margin-left: auto; 
    font-size: 10px; 
    color: #aeb3bd; 
    transition: transform 0.2s; 
}

.user.active .toggle-icon { 
    transform: rotate(180deg); 
}

.user-popover { 
    position: absolute; 
    bottom: calc(100% + 12px); 
    left: 0; 
    width: 210px; 
    background: #ffffff; 
    color: #20242a; 
    border-radius: 8px; 
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25); 
    padding: 14px; 
    display: none; 
    z-index: 100; 
    animation: popoverFadeIn 0.2s ease; 
}

.user-popover.show { 
    display: block; 
}

@keyframes popoverFadeIn { 
    from { opacity: 0; transform: translateY(6px); } 
    to { opacity: 1; transform: translateY(0); } 
}

.popover-header { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    padding-bottom: 10px; 
    border-bottom: 1px solid #eee; 
}

.popover-avatar { 
    font-size: 28px; 
    color: #74473b; 
}

.badge-role { 
    display: inline-block; 
    font-size: 9px; 
    background: #f0ecea; 
    color: #74473b; 
    padding: 2px 6px; 
    border-radius: 4px; 
    font-weight: 700; 
    margin-top: 3px; 
}

.popover-body { 
    padding: 10px 0; 
}

.info-row { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    font-size: 11px; 
    color: #555; 
}

.info-row i { 
    color: #74473b; 
    width: 14px; 
}

.popover-footer { 
    padding-top: 10px; 
    border-top: 1px solid #eee; 
}

.popover-logout { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    color: #e74c3c; 
    text-decoration: none; 
    font-size: 12px; 
    font-weight: 600; 
    padding: 6px 8px; 
    border-radius: 5px; 
    transition: background 0.15s; 
}

.popover-logout:hover { 
    background: #fdf2f2; 
}

.settings { 
    border: 0; 
    background: transparent; 
    color: #fff; 
    font-size: 18px; 
    text-decoration: none; 
    cursor: pointer; 
    display: flex; 
    align-items: center; 
}

/* =========================================================
   CONTENT & TOPBAR
   ========================================================= */
.content {
    margin-left: 245px;
    width: calc(100% - 245px);
    padding: 35px 40px;
}

.topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 30px;
}

.topbar h1 {
    font-size: 28px;
    margin-bottom: 6px;
}

.topbar p {
    color: #555d68;
    font-size: 13px;
}

.refresh {
    border: 1px solid #dfe2e7;
    background: white;
    border-radius: 7px;
    padding: 10px 16px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
}

.refresh:hover {
    background: #f1f2f4;
}

/* =========================================================
   STAT CARDS & AESTHETIC ICONS
   ========================================================= */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border: 1px solid #e4e6ea;
    border-radius: 12px;
    padding: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 125px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
}

.eyebrow {
    display: block;
    color: #555d68;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .7px;
    margin-bottom: 12px;
}

.stat-card strong {
    font-size: 26px;
    font-weight: 700;
}

.stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    transition: transform 0.2s ease;
}

.stat-card:hover .stat-icon {
    transform: scale(1.05);
}

.stat-icon.blue { 
    background: #eff6ff; 
    color: #2563eb; 
}

.stat-icon.green { 
    background: #ecfdf5; 
    color: #059669; 
}

.stat-icon.orange { 
    background: #fff7ed; 
    color: #ea580c; 
}

/* =========================================================
   DASHBOARD GRID & PANELS
   ========================================================= */
.dashboard-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 22px;
}

.left-column {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

.panel {
    background: white;
    border: 1px solid #e4e6ea;
    border-radius: 10px;
}

.panel h2 {
    font-size: 17px;
    font-weight: 700;
}

.sales-card {
    padding: 22px;
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sales-header {
    margin-bottom: 25px;
}

.segmented {
    display: flex;
    background: #f1f2f4;
    border-radius: 7px;
    padding: 3px;
}

.segmented button {
    border: none;
    background: transparent;
    padding: 7px 11px;
    border-radius: 5px;
    font-size: 11px;
    color: #555d68;
    cursor: pointer;
    font-family: inherit;
    font-weight: 600;
}

.segmented button.selected {
    background: white;
    color: #20242b;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}

/* =========================================================
   CHART
   ========================================================= */
.chart-wrap {
    height: 260px;
    position: relative;
    padding: 15px 10px 35px;
}

.y-grid {
    position: absolute;
    left: 0;
    right: 0;
    top: 15px;
    bottom: 35px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.y-grid span {
    width: 100%;
    height: 1px;
    background: #eeeeef;
}

.bars {
    position: relative;
    z-index: 2;
    height: 100%;
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    gap: 15px;
}

.bar-group {
    height: 100%;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-direction: column;
    gap: 9px;
}

.bar {
    width: 70%;
    max-width: 50px;
    min-height: 2px;
    background: #b9c0ca;
    border-radius: 5px 5px 0 0;
    transition: .3s;
}

.bar:hover {
    background: #737b87;
}

.bar-group label {
    font-size: 10px;
    color: #555d68;
    font-weight: 600;
}

/* =========================================================
   TRANSACTIONS
   ========================================================= */
.transactions-card {
    overflow: hidden;
}

.transaction-title-row {
    padding: 20px 22px;
    border-bottom: 1px solid #eeeeef;
}

.date {
    margin-left: auto;
    margin-right: 20px;
    color: #555d68;
    font-size: 11px;
}

.view-all {
    border: none;
    background: transparent;
    color: #555d68;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    text-align: left;
    background: #fafafa;
    color: #555d68;
    font-size: 10px;
    font-weight: 700;
    padding: 13px 16px;
    white-space: nowrap;
}

td {
    padding: 14px 16px;
    border-top: 1px solid #eeeeef;
    font-size: 11px;
    color: #444a54;
    vertical-align: middle;
}

td:first-child {
    font-weight: 700;
    color: #292e36;
}

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

.badge.complete {
    background: #e6f7ec;
    color: #23834a;
}

.badge.preparing {
    background: #fff0dc;
    color: #b66a12;
}

/* =========================================================
   TOP SELLING
   ========================================================= */
.top-selling {
    padding: 22px;
    height: fit-content;
}

.top-selling h2 {
    margin-bottom: 20px;
}

.product-row {
    display: grid;
    grid-template-columns: 45px 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 17px 0;
    border-top: 1px solid #eeeeef;
}

.product-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #f3f4f6;
    color: #4b5563;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.product-icon.warm {
    background: #fdf2f0;
    color: #74473b;
}

.product-info {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

.product-info strong {
    font-size: 12px;
    line-height: 1.3;
}

.product-info span {
    color: #555d68;
    font-size: 10px;
}

.price {
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.no-data {
    text-align: center;
    color: #666c75;
    padding: 25px !important;
}

/* =========================================================
   RESPONSIVE
   ========================================================= */
@media (max-width: 1000px) {
    .dashboard-grid { grid-template-columns: 1fr; }
    .top-selling { width: 100%; }
}

@media (max-width: 800px) {
    .stats-grid { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .sidebar { position: relative; width: 100%; min-height: auto; padding: 22px 0; }
    .app-shell { display: block; }
    .brand { padding-bottom: 20px; }
    .nav { display: flex; flex-wrap: wrap; }
    .nav-item { padding: 10px 14px; }
    .sidebar-footer { margin-top: 18px; }
    .content { width: 100%; margin-left: 0; padding: 30px 16px; }
}
</style>
<link rel="stylesheet" href="../assets/sidebar.css">
</head>

<body>

<main class="app-shell">

<!-- =====================================================
     SIDEBAR
     ===================================================== -->
<aside class="sidebar">
    <div>
        <div class="brand">COFFEE MAKER</div>
        <nav class="nav">
            <?php if ($user_role !== "cashier"): ?>
                <a class="nav-item active" href="dashboard.php">DASHBOARD</a>
            <?php endif; ?>
            <a class="nav-item" href="pos.php">POS</a>
            <a class="nav-item" href="orders.php">ORDERS</a>
            <?php if ($user_role !== "cashier"): ?>
                <a class="nav-item" href="products.php">PRODUCTS</a>
                <a class="nav-item" href="users.php">USERS</a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- SIDEBAR FOOTER WITH PROFILE POPOVER -->
    <div class="sidebar-footer">
        <div class="user-wrapper">
            <div class="user" id="userProfileBtn" role="button" tabindex="0">
                <div class="avatar"><i class="fas fa-user"></i></div>
                <div class="user-details-text">
                    <span class="user-name"><?= htmlspecialchars($user_name) ?></span>
                    <small class="user-role"><?= htmlspecialchars(ucfirst($user_role)) ?></small>
                </div>
                <i class="fas fa-chevron-up toggle-icon"></i>
            </div>

            <div class="user-popover" id="userPopover">
                <div class="popover-header">
                    <div class="popover-avatar"><i class="fas fa-user-circle"></i></div>
                    <div>
                        <strong><?= htmlspecialchars($user_name) ?></strong>
                        <span class="badge-role"><?= htmlspecialchars(strtoupper($user_role)) ?></span>
                    </div>
                </div>
                <div class="popover-body">
                    <div class="info-row">
                        <i class="fas fa-phone-alt"></i>
                        <span><?= htmlspecialchars($user_phone) ?></span>
                    </div>
                </div>
                <div class="popover-footer">
                    <a href="../logout.php" class="popover-logout" onclick="return confirm('Are you sure you want to log out?');">
                        <i class="fas fa-sign-out-alt"></i> Log Out
                    </a>
                </div>
            </div>
        </div>

        <?php if ($user_role !== "cashier"): ?>
            <a class="settings" href="settings.php" aria-label="Settings" title="Settings"><i class="fas fa-cog"></i></a>
        <?php endif; ?>
    </div>
</aside>

<!-- =====================================================
     CONTENT
     ===================================================== -->
<section class="content">

<header class="topbar">
    <div>
        <h1>Dashboard</h1>
        <p><?= date("l, F d, Y") ?></p>
    </div>
    <button class="refresh" id="refreshBtn">↻&nbsp; Refresh</button>
</header>

<!-- STATS GRID -->
<section class="stats-grid">
    <article class="stat-card">
        <div>
            <span class="eyebrow">TOTAL ORDERS</span>
            <strong><?= number_format($total_orders) ?></strong>
        </div>
        <div class="stat-icon blue">
            <i class="fa-solid fa-cart-shopping"></i>
        </div>
    </article>

    <article class="stat-card">
        <div>
            <span class="eyebrow">TODAY'S SALES</span>
            <strong>₱<?= number_format($today_sales, 2) ?></strong>
        </div>
        <div class="stat-icon green">
            <i class="fa-solid fa-wallet"></i>
        </div>
    </article>

    <article class="stat-card">
        <div>
            <span class="eyebrow">TOTAL PRODUCTS</span>
            <strong><?= number_format($total_products) ?></strong>
        </div>
        <div class="stat-icon orange">
            <i class="fa-solid fa-box-open"></i>
        </div>
    </article>
</section>

<!-- DASHBOARD GRID -->
<section class="dashboard-grid">
    <div class="left-column">
        <!-- SALES OVERVIEW -->
        <article class="panel sales-card">
            <div class="panel-header sales-header">
                <h2>Sales Overview</h2>
                <div class="segmented" id="rangeTabs">
                    <button class="selected" data-range="today">Today</button>
                    <button data-range="week">This Week</button>
                    <button data-range="month">This Month</button>
                </div>
            </div>

            <div class="chart-wrap">
                <div class="y-grid">
                    <span></span><span></span><span></span><span></span>
                </div>
                <div class="bars" id="bars">
                    <?php foreach ($chart_days as $day): ?>
                        <?php
                        $height = ($day["sales"] / $max_sales) * 100;
                        if ($height < 3 && $day["sales"] > 0) {
                            $height = 3;
                        }
                        ?>
                        <div class="bar-group">
                            <div class="bar" style="height: <?= $height ?>%;" title="₱<?= number_format($day["sales"], 2) ?>"></div>
                            <label><?= htmlspecialchars($day["label"]) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <!-- RECENT TRANSACTIONS -->
        <article class="panel transactions-card">
            <div class="panel-header transaction-title-row">
                <h2>Recent Transactions</h2>
                <span class="date"><?= date("F d, Y") ?></span>
                <button class="view-all" onclick="window.location.href='orders.php'">View All</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date/Time</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($transactions) > 0): ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= htmlspecialchars($transaction["order_number"]) ?></td>
                                    <td>
                                        <?= date("M d, Y", strtotime($transaction["created_at"])) ?><br>
                                        <?= date("h:i A", strtotime($transaction["created_at"])) ?>
                                    </td>
                                    <td><?= htmlspecialchars(!empty($transaction["customer_name"]) ? $transaction["customer_name"] : "Walk-in") ?></td>
                                    <td>₱<?= number_format($transaction["total_amount"], 2) ?></td>
                                    <td><?= htmlspecialchars($transaction["payment_method"]) ?></td>
                                    <td>
                                        <?php
                                        $status = strtolower($transaction["status"]);
                                        $badge_class = "complete";
                                        if ($status === "preparing" || $status === "pending") {
                                            $badge_class = "preparing";
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($transaction["status"]) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="no-data">No transactions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <!-- TOP SELLING -->
    <aside class="panel top-selling">
        <h2>Top-Selling</h2>
        <?php if (count($top_products) > 0): ?>
            <?php foreach ($top_products as $product): ?>
                <div class="product-row">
                    <div class="product-icon warm">
                        <i class="fa-solid fa-mug-hot"></i>
                    </div>
                    <div class="product-info">
                        <strong><?= htmlspecialchars($product["product_name"]) ?></strong>
                        <span><?= number_format($product["total_quantity"]) ?> Sold</span>
                    </div>
                    <div class="price">₱<?= number_format($product["total_sales"], 2) ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-data">No sales data available.</p>
        <?php endif; ?>
    </aside>
</section>

</section>
</main>

<script>
// Refresh Button
document.getElementById("refreshBtn")?.addEventListener("click", function () {
    location.reload();
});

// Sales Range Tabs
const rangeButtons = document.querySelectorAll("#rangeTabs button");
rangeButtons.forEach(function(button) {
    button.addEventListener("click", function() {
        rangeButtons.forEach(btn => btn.classList.remove("selected"));
        this.classList.add("selected");
    });
});

// Profile Popover Toggle
const userProfileBtn = document.getElementById('userProfileBtn');
const userPopover = document.getElementById('userPopover');

if (userProfileBtn && userPopover) {
    userProfileBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        userProfileBtn.classList.toggle('active');
        userPopover.classList.toggle('show');
    });

    document.addEventListener('click', (event) => {
        if (!userPopover.contains(event.target) && !userProfileBtn.contains(event.target)) {
            userProfileBtn.classList.remove('active');
            userPopover.classList.remove('show');
        }
    });
}
</script>
</body>
</html>