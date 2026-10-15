<?php
// Set PHP Default Timezone to Philippine Standard Time (UTC+8)
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Login Guard
$is_logged_in = isset($_SESSION["username"]) || isset($_SESSION["admin"]);
if (!$is_logged_in) {
    header("Location: ../index.php");
    exit();
}

// 2. Role Guard (Admin Only)
$user_role = strtolower($_SESSION["role"] ?? "");
if ($user_role !== "admin") {
    header("Location: pos.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

// Sync MySQL database session time zone with Asia/Manila (UTC+8)
if (isset($conn)) {
    $conn->query("SET time_zone = '+08:00'");
}

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
   HELPERS
   ========================================================= */
function period_totals($conn, $from, $to) {
    $out = ["sales" => 0.0, "orders" => 0];
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS sales, COUNT(*) AS orders
        FROM orders
        WHERE created_at >= ? AND created_at < ?
        AND status != 'Cancelled'
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $out["sales"]  = (float)$row["sales"];
            $out["orders"] = (int)$row["orders"];
        }
        $stmt->close();
    }
    return $out;
}

// Rounds a value up to a "nice" axis maximum (divides cleanly into 4 steps)
function nice_ceiling($v) {
    if ($v <= 0) return 100;
    $base = pow(10, floor(log10($v)));
    $f = $v / $base;
    foreach ([1, 2, 4, 5, 8, 10] as $n) {
        if ($f <= $n) return $n * $base;
    }
    return 10 * $base;
}

// ₱1.2k style label for the Y-axis
function peso_short($v) {
    if ($v >= 1000) {
        return "₱" . rtrim(rtrim(number_format($v / 1000, 1), "0"), ".") . "k";
    }
    return "₱" . number_format($v, 0);
}

/* =========================================================
   BEST SELLING PRODUCTS (THIS MONTH) - DONUT + RANKING
   ========================================================= */
$month_start      = date("Y-m-01");
$next_month_start = date("Y-m-01", strtotime("first day of next month"));
$donut_colors     = ["#2B1610", "#74473b", "#A9786A", "#D2B0A4", "#EBDCD6"];
$donut_text_colors = ["#ffffff", "#ffffff", "#ffffff", "#2B1610", "#2B1610"];

$top_products  = [];
$top_total_qty = 0;
$stmt = $conn->prepare("
    SELECT
        p.product_name,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.subtotal) AS total_sales
    FROM order_items oi
    INNER JOIN products p ON oi.product_id = p.id
    INNER JOIN orders o   ON oi.order_id = o.id
    WHERE o.status != 'Cancelled'
    AND o.created_at >= ? AND o.created_at < ?
    GROUP BY p.id, p.product_name
    ORDER BY total_quantity DESC, total_sales DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("ss", $month_start, $next_month_start);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $row["total_quantity"] = (int)$row["total_quantity"];
        $top_products[] = $row;
        $top_total_qty += $row["total_quantity"];
    }
    $stmt->close();
}

// Donut geometry (SVG circle segments)
$donut_r    = 64;
$donut_circ = 2 * M_PI * $donut_r;
$donut_gap  = count($top_products) > 1 ? 3 : 0;
$donut_segments = [];
$donut_cum = 0;
foreach ($top_products as $i => $p) {
    $frac = $top_total_qty > 0 ? $p["total_quantity"] / $top_total_qty : 0;
    $len  = max(0, $frac * $donut_circ - $donut_gap);
    $donut_segments[] = [
        "color"  => $donut_colors[$i % count($donut_colors)],
        "len"    => $len,
        "offset" => -$donut_cum,
        "pct"    => round($frac * 100),
    ];
    $donut_cum += $frac * $donut_circ;
}

/* =========================================================
   SALES OVERVIEW (DYNAMIC RANGE FILTER)
   ========================================================= */

$range = $_GET["range"] ?? "today";
if (!in_array($range, ["today", "week", "month"])) {
    $range = "today";
}

$today    = date("Y-m-d");
$tomorrow = date("Y-m-d", strtotime("+1 day"));
$chart_days = [];

if ($range === "today") {
    // Hourly bars (store hours 7 AM - 9 PM, extended if orders fall outside)
    $by_hour = [];
    $result = $conn->query("
        SELECT HOUR(created_at) AS k,
               COALESCE(SUM(total_amount), 0) AS sales,
               COUNT(*) AS orders
        FROM orders
        WHERE created_at >= '$today' AND created_at < '$tomorrow'
        AND status != 'Cancelled'
        GROUP BY HOUR(created_at)
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $by_hour[(int)$row["k"]] = ["sales" => (float)$row["sales"], "orders" => (int)$row["orders"]];
        }
    }
    $h_start = 7;
    $h_end   = 21;
    if ($by_hour) {
        $h_start = min($h_start, min(array_keys($by_hour)));
        $h_end   = max($h_end, max(array_keys($by_hour)));
    }
    for ($h = $h_start; $h <= $h_end; $h++) {
        $ts = mktime($h, 0, 0);
        $chart_days[] = [
            "label"  => date("gA", $ts),
            "short"  => date("g A", $ts),
            "sub"    => "",
            "full"   => date("g:00 A", $ts) . " – " . date("g:59 A", $ts),
            "sales"  => $by_hour[$h]["sales"] ?? 0,
            "orders" => $by_hour[$h]["orders"] ?? 0,
            "future" => false,
        ];
    }
    $prev = period_totals($conn, date("Y-m-d", strtotime("-1 day")), $today);
    $compare_label = "vs yesterday";
    $range_title   = "Hourly sales today";
    $peak_noun     = "Busiest hour";

} elseif ($range === "week") {
    // Last 7 days (including today)
    $by_day = [];
    $from = date("Y-m-d", strtotime("-6 days"));
    $result = $conn->query("
        SELECT DATE(created_at) AS k,
               COALESCE(SUM(total_amount), 0) AS sales,
               COUNT(*) AS orders
        FROM orders
        WHERE created_at >= '$from' AND created_at < '$tomorrow'
        AND status != 'Cancelled'
        GROUP BY DATE(created_at)
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $by_day[$row["k"]] = ["sales" => (float)$row["sales"], "orders" => (int)$row["orders"]];
        }
    }
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i days");
        $d  = date("Y-m-d", $ts);
        $chart_days[] = [
            "label"  => date("D", $ts),
            "short"  => date("D, M j", $ts),
            "sub"    => date("M j", $ts),
            "full"   => date("l, F j", $ts),
            "sales"  => $by_day[$d]["sales"] ?? 0,
            "orders" => $by_day[$d]["orders"] ?? 0,
            "future" => false,
        ];
    }
    $prev = period_totals($conn, date("Y-m-d", strtotime("-13 days")), $from);
    $compare_label = "vs previous 7 days";
    $range_title   = "Daily sales, last 7 days";
    $peak_noun     = "Best day";

} else {
    // Current month, one bar per day
    $by_day = [];
    $result = $conn->query("
        SELECT DATE(created_at) AS k,
               COALESCE(SUM(total_amount), 0) AS sales,
               COUNT(*) AS orders
        FROM orders
        WHERE created_at >= '$month_start' AND created_at < '$next_month_start'
        AND status != 'Cancelled'
        GROUP BY DATE(created_at)
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $by_day[$row["k"]] = ["sales" => (float)$row["sales"], "orders" => (int)$row["orders"]];
        }
    }
    $days_in_month = (int)date("t");
    $day_now = (int)date("j");
    for ($d = 1; $d <= $days_in_month; $d++) {
        $key = date("Y-m-") . sprintf("%02d", $d);
        $ts  = strtotime($key);
        $chart_days[] = [
            "label"  => (string)$d,
            "short"  => date("M j", $ts),
            "sub"    => "",
            "full"   => date("l, F j", $ts),
            "sales"  => $by_day[$key]["sales"] ?? 0,
            "orders" => $by_day[$key]["orders"] ?? 0,
            "future" => $d > $day_now,
        ];
    }
    // Compare against the same number of days elapsed last month
    $last_month_start = date("Y-m-01", strtotime("first day of last month"));
    $last_month_end   = date("Y-m-d", strtotime("$last_month_start +$day_now days"));
    if ($last_month_end > $month_start) {
        $last_month_end = $month_start;
    }
    $prev = period_totals($conn, $last_month_start, $last_month_end);
    $compare_label = "vs same days last month";
    $range_title   = "Daily sales, " . date("F Y");
    $peak_noun     = "Best day";
}

// Summary figures
$sum_sales  = 0;
$sum_orders = 0;
$max_sales  = 0;
$peak       = null;
foreach ($chart_days as $d) {
    $sum_sales  += $d["sales"];
    $sum_orders += $d["orders"];
    if ($d["sales"] > $max_sales) {
        $max_sales = $d["sales"];
        $peak = $d;
    }
}
$avg_order = $sum_orders > 0 ? $sum_sales / $sum_orders : 0;

// % change vs previous period (null when there is nothing to compare to)
$change_pct = null;
if (($prev["sales"] ?? 0) > 0) {
    $change_pct = (($sum_sales - $prev["sales"]) / $prev["sales"]) * 100;
}

// Y-axis scale (4 steps)
$y_max   = nice_ceiling($max_sales);
$y_ticks = [];
for ($i = 4; $i >= 0; $i--) {
    $y_ticks[] = $y_max * $i / 4;
}
$bar_count = count($chart_days);
// Show value labels above bars only when there is room
$show_values = $bar_count <= 16;

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
   SIDEBAR STYLES
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
   STAT CARDS
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
    grid-template-columns: minmax(0, 1fr) 340px;
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
    margin-bottom: 20px;
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
   SALES SUMMARY + CHART
   ========================================================= */
.sales-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 28px;
}

.sum-item {
    background: #faf7f5;
    border: 1px solid #f0e8e4;
    border-radius: 10px;
    padding: 13px 14px;
    min-width: 0;
}

.sum-label {
    display: block;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: .6px;
    color: #7a6a63;
    margin-bottom: 6px;
}

.sum-value {
    display: block;
    font-size: 19px;
    font-weight: 700;
    color: #2B1610;
    white-space: nowrap;
}

.sum-sub {
    display: block;
    margin-top: 5px;
    font-size: 10px;
    color: #7a6a63;
}

.change {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    margin-top: 5px;
}

.change.up   { background: #e6f7ec; color: #23834a; }
.change.down { background: #fdf2f2; color: #e74c3c; }
.change.flat { background: #f1f2f4; color: #555d68; }

.chart-title-sub {
    font-size: 11px;
    color: #7a6a63;
    font-weight: 500;
    margin-top: 3px;
}

.chart-area {
    display: flex;
    gap: 10px;
    margin-bottom: 46px;
}

.y-axis {
    position: relative;
    width: 46px;
    height: 230px;
    flex-shrink: 0;
}

.y-axis span {
    position: absolute;
    right: 0;
    transform: translateY(50%);
    font-size: 10px;
    color: #7a6a63;
    font-weight: 600;
}

.plot {
    position: relative;
    flex: 1;
    height: 230px;
    min-width: 0;
}

.gridline {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: #eeeeef;
}

.gridline.base {
    background: #d9d3d0;
}

.bars {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: stretch;
    gap: 6px;
    z-index: 2;
}

.bar-col {
    position: relative;
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.bar {
    width: min(70%, 46px);
    height: var(--h);
    min-height: 2px;
    background: #74473b;
    border-radius: 5px 5px 0 0;
    transition: background .2s;
}

.bar.zero {
    background: #e6dfdb;
    height: 2px;
}

.bar.peak {
    background: #2B1610;
}

.bar-col:hover .bar:not(.zero) {
    background: #a0614f;
}

.val {
    position: absolute;
    bottom: calc(var(--h) + 4px);
    font-size: 10px;
    font-weight: 700;
    color: #4a2a20;
    white-space: nowrap;
}

.x-label {
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    text-align: center;
    font-size: 10px;
    font-weight: 600;
    color: #555d68;
    line-height: 1.35;
    white-space: nowrap;
}

.x-label small {
    display: block;
    font-size: 9px;
    font-weight: 500;
    color: #8a7f7a;
}

.bar-col.future .x-label {
    color: #b8b0ac;
}

.tip {
    display: none;
    position: absolute;
    bottom: calc(var(--h) + 24px);
    left: 50%;
    transform: translateX(-50%);
    background: #2B1610;
    color: #fff;
    padding: 8px 11px;
    border-radius: 8px;
    font-size: 10.5px;
    line-height: 1.5;
    white-space: nowrap;
    z-index: 20;
    pointer-events: none;
    box-shadow: 0 6px 16px rgba(0, 0, 0, .25);
}

.tip strong {
    display: block;
    font-size: 11.5px;
}

.bar-col:hover .tip {
    display: block;
}

.chart-empty {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    text-align: center;
    color: #7a6a63;
    font-size: 12px;
    z-index: 3;
    pointer-events: none;
}

.chart-empty i {
    display: block;
    font-size: 22px;
    margin-bottom: 8px;
    color: #cdbfb8;
}

/* =========================================================
   TRANSACTIONS & BADGES
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

.badge.cancelled {
    background: #fdf2f2;
    color: #e74c3c;
}

/* =========================================================
   BEST SELLING PRODUCTS (DONUT + RANKING)
   ========================================================= */
.top-selling {
    padding: 0;
    height: fit-content;
    overflow: hidden;
}

.top-selling h2 {
    text-align: center;
    font-size: 16px;
    font-weight: 800;
    color: #20242b;
    padding: 20px 18px 16px;
    border-bottom: 1px solid #eeeeef;
    line-height: 1.35;
}

.best-body {
    padding: 22px 20px 20px;
}

.donut-wrap {
    position: relative;
    width: 190px;
    height: 190px;
    margin: 0 auto 20px;
}

.donut-wrap svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}

.donut-seg {
    transition: opacity .2s;
    cursor: pointer;
}

.donut-wrap:hover .donut-seg {
    opacity: .55;
}

.donut-wrap .donut-seg:hover {
    opacity: 1;
}

.donut-center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.donut-center strong {
    font-size: 26px;
    font-weight: 800;
    color: #2B1610;
    line-height: 1;
}

.donut-center span {
    font-size: 10px;
    color: #7a6a63;
    margin-top: 4px;
    font-weight: 600;
}

.donut-legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 16px;
    margin-bottom: 22px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #6b6b6b;
}

.legend-swatch {
    width: 30px;
    height: 11px;
    flex-shrink: 0;
}

.rank-list {
    display: flex;
    flex-direction: column;
}

.rank-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid #ececec;
}

.rank-row:last-child {
    border-bottom: 0;
}

.rank-badge {
    width: 40px;
    flex-shrink: 0;
    text-align: center;
    background: #f0ecea;
    color: #74473b;
    font-size: 12px;
    font-weight: 700;
    padding: 7px 0;
    border-radius: 12px;
}

.rank-name {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    color: #20242b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rank-sold {
    font-size: 13px;
    font-weight: 800;
    color: #20242b;
    white-space: nowrap;
}

.no-data {
    text-align: center;
    color: #666c75;
    padding: 25px !important;
    font-size: 12px;
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
    .sales-summary { grid-template-columns: repeat(2, 1fr); }
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
                <div class="popover-footer">
                    <a href="../logout.php" class="popover-logout">
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
                    <button class="<?= $range === 'today' ? 'selected' : '' ?>" data-range="today">Today</button>
                    <button class="<?= $range === 'week' ? 'selected' : '' ?>" data-range="week">This Week</button>
                    <button class="<?= $range === 'month' ? 'selected' : '' ?>" data-range="month">This Month</button>
                </div>
            </div>

            <div class="sales-summary">
                <div class="sum-item">
                    <span class="sum-label">TOTAL SALES</span>
                    <span class="sum-value">₱<?= number_format($sum_sales, 2) ?></span>
                    <?php if ($change_pct !== null): ?>
                        <?php
                        $chg_class = $change_pct > 0.05 ? "up" : ($change_pct < -0.05 ? "down" : "flat");
                        $chg_icon  = $change_pct > 0.05 ? "fa-arrow-up" : ($change_pct < -0.05 ? "fa-arrow-down" : "fa-minus");
                        ?>
                        <span class="change <?= $chg_class ?>">
                            <i class="fa-solid <?= $chg_icon ?>"></i> <?= number_format(abs($change_pct), 1) ?>%
                        </span>
                        <span class="sum-sub" style="display:inline;margin-left:4px;"><?= htmlspecialchars($compare_label) ?></span>
                    <?php else: ?>
                        <span class="sum-sub">No data <?= htmlspecialchars($compare_label) ?></span>
                    <?php endif; ?>
                </div>

                <div class="sum-item">
                    <span class="sum-label">ORDERS</span>
                    <span class="sum-value"><?= number_format($sum_orders) ?></span>
                    <span class="sum-sub">
                        <?= $prev["orders"] > 0 ? number_format($prev["orders"]) . " " . htmlspecialchars($compare_label) : "Completed orders" ?>
                    </span>
                </div>

                <div class="sum-item">
                    <span class="sum-label">AVG. ORDER VALUE</span>
                    <span class="sum-value">₱<?= number_format($avg_order, 2) ?></span>
                    <span class="sum-sub">Per completed order</span>
                </div>

                <div class="sum-item">
                    <span class="sum-label"><?= strtoupper($peak_noun) ?></span>
                    <span class="sum-value"><?= $peak ? htmlspecialchars($peak["short"]) : "—" ?></span>
                    <span class="sum-sub">
                        <?= $peak ? "₱" . number_format($peak["sales"], 2) . " · " . number_format($peak["orders"]) . " order" . ($peak["orders"] == 1 ? "" : "s") : "No sales yet" ?>
                    </span>
                </div>
            </div>

            <div class="chart-title-sub" style="margin:0 0 18px;"><?= htmlspecialchars($range_title) ?> (₱, excluding cancelled orders)</div>

            <div class="chart-area">
                <div class="y-axis">
                    <?php foreach ($y_ticks as $i => $tick): ?>
                        <span style="bottom: <?= $i * 25 ?>%;"><?= peso_short($y_ticks[4 - $i]) ?></span>
                    <?php endforeach; ?>
                </div>

                <div class="plot">
                    <?php for ($i = 0; $i <= 4; $i++): ?>
                        <div class="gridline <?= $i === 0 ? 'base' : '' ?>" style="bottom: <?= $i * 25 ?>%;"></div>
                    <?php endfor; ?>

                    <?php if ($sum_sales <= 0): ?>
                        <div class="chart-empty">
                            <div><i class="fa-regular fa-chart-bar"></i>No sales recorded for this period yet.</div>
                        </div>
                    <?php endif; ?>

                    <div class="bars" id="bars">
                        <?php foreach ($chart_days as $day): ?>
                            <?php
                            $pct = ($day["sales"] / $y_max) * 100;
                            if ($day["sales"] > 0 && $pct < 2) {
                                $pct = 2;
                            }
                            $is_peak = $peak && $day["sales"] > 0 && $day["sales"] == $peak["sales"] && $day["label"] === $peak["label"];
                            ?>
                            <div class="bar-col <?= $day["future"] ? 'future' : '' ?>" style="--h: <?= round($pct, 2) ?>%;">
                                <?php if ($day["sales"] > 0 && ($show_values || $is_peak)): ?>
                                    <span class="val"><?= peso_short($day["sales"]) ?></span>
                                <?php endif; ?>
                                <div class="bar <?= $day["sales"] > 0 ? ($is_peak ? 'peak' : '') : 'zero' ?>"></div>
                                <div class="x-label">
                                    <?= htmlspecialchars($day["label"]) ?>
                                    <?php if ($day["sub"] !== ""): ?><small><?= htmlspecialchars($day["sub"]) ?></small><?php endif; ?>
                                </div>
                                <?php if (!$day["future"]): ?>
                                    <div class="tip">
                                        <strong><?= htmlspecialchars($day["full"]) ?></strong>
                                        Sales: ₱<?= number_format($day["sales"], 2) ?><br>
                                        Orders: <?= number_format($day["orders"]) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                                        $status_raw = $transaction["status"] ?? "Completed";
                                        $status_clean = strtolower(trim($status_raw));
                                        
                                        if ($status_clean === "cancelled") {
                                            $display_status = "Cancelled";
                                            $badge_class = "cancelled";
                                        } else {
                                            $display_status = "Completed";
                                            $badge_class = "complete";
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= $display_status ?></span>
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

    <!-- BEST SELLING PRODUCTS (THIS MONTH) -->
    <aside class="panel top-selling">
        <h2>Best Selling Products (This Month)</h2>
        <div class="best-body">
            <?php if (count($top_products) > 0): ?>
                <div class="donut-wrap">
                    <svg viewBox="0 0 200 200" role="img" aria-label="Best selling products this month">
                        <?php foreach ($donut_segments as $i => $seg): ?>
                            <circle class="donut-seg" cx="100" cy="100" r="<?= $donut_r ?>" fill="none"
                                    stroke="<?= $seg["color"] ?>" stroke-width="34"
                                    stroke-dasharray="<?= round($seg["len"], 3) ?> <?= round($donut_circ - $seg["len"], 3) ?>"
                                    stroke-dashoffset="<?= round($seg["offset"], 3) ?>">
                                <title><?= htmlspecialchars($top_products[$i]["product_name"]) ?>: <?= $top_products[$i]["total_quantity"] ?> sold (<?= $seg["pct"] ?>%)</title>
                            </circle>
                        <?php endforeach; ?>
                    </svg>
                    <div class="donut-center">
                        <strong><?= number_format($top_total_qty) ?></strong>
                        <span>units sold</span>
                    </div>
                </div>

                <div class="donut-legend">
                    <?php foreach ($top_products as $i => $product): ?>
                        <div class="legend-item">
                            <span class="legend-swatch" style="background: <?= $donut_colors[$i % count($donut_colors)] ?>;"></span>
                            <?= htmlspecialchars($product["product_name"]) ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="rank-list">
                    <?php foreach ($top_products as $i => $product): ?>
                        <div class="rank-row">
                            <span class="rank-badge" style="background: <?= $donut_colors[$i % count($donut_colors)] ?>; color: <?= $donut_text_colors[$i % count($donut_text_colors)] ?>;">#<?= $i + 1 ?></span>
                            <span class="rank-name" title="<?= htmlspecialchars($product["product_name"]) ?> — ₱<?= number_format($product["total_sales"], 2) ?>"><?= htmlspecialchars($product["product_name"]) ?></span>
                            <span class="rank-sold"><?= number_format($product["total_quantity"]) ?> sold</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-data">No sales recorded this month yet.</p>
            <?php endif; ?>
        </div>
    </aside>
</section>

</section>
</main>

<script>
// Refresh Button
document.getElementById("refreshBtn")?.addEventListener("click", function () {
    location.reload();
});

// Sales Range Tabs Filter Navigation
const rangeButtons = document.querySelectorAll("#rangeTabs button");
rangeButtons.forEach(function(button) {
    button.addEventListener("click", function() {
        const range = this.getAttribute("data-range");
        window.location.href = "?range=" + range;
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
<!-- ===== Log Out Warning Modal ===== -->
<style>
.lgo-overlay { position: fixed; inset: 0; background: rgba(43, 22, 16, 0.55); display: none; align-items: center; justify-content: center; z-index: 99999; padding: 16px; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.lgo-overlay.lgo-open { display: flex; }
.lgo-box { width: min(100%, 360px); background: #fff; border-radius: 12px; padding: 26px 24px 20px; text-align: center; box-shadow: 0 18px 45px rgba(0,0,0,.3); animation: lgoPop .18s ease-out; }
@keyframes lgoPop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.lgo-icon { width: 54px; height: 54px; margin: 0 auto 14px; border-radius: 50%; background: #fdecea; color: #e74c3c; display: grid; place-items: center; font-size: 22px; }
.lgo-box h3 { font-size: 17px; font-weight: 800; color: #2b1610; margin: 0 0 6px; }
.lgo-box p { font-size: 12px; color: #5c4f4a; line-height: 1.5; margin: 0 0 20px; }
.lgo-actions { display: flex; gap: 10px; }
.lgo-actions button, .lgo-actions a { flex: 1; height: 40px; border-radius: 8px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.lgo-cancel { border: 1px solid #ded5d1; background: #fff; color: #2b1610; }
.lgo-cancel:hover { background: #f7f3f1; }
.lgo-confirm { border: 0; background: #e74c3c; color: #fff; }
.lgo-confirm:hover { background: #cf3f2f; }
</style>

<div class="lgo-overlay" id="lgoOverlay" role="dialog" aria-modal="true" aria-labelledby="lgoTitle">
    <div class="lgo-box">
        <div class="lgo-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3 id="lgoTitle">Log out?</h3>
        <p>Are you sure you want to log out? You will need to sign in again to continue.</p>
        <div class="lgo-actions">
            <button type="button" class="lgo-cancel" id="lgoCancel">Cancel</button>
            <a href="../logout.php" class="lgo-confirm" id="lgoConfirm">Yes, Log Out</a>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('lgoOverlay');
    var cancelBtn = document.getElementById('lgoCancel');
    var confirmBtn = document.getElementById('lgoConfirm');
    if (!overlay) return;

    function openLogoutWarning() { overlay.classList.add('lgo-open'); cancelBtn.focus(); }
    function closeLogoutWarning() { overlay.classList.remove('lgo-open'); }

    document.querySelectorAll('.popover-logout').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            confirmBtn.setAttribute('href', link.getAttribute('href'));
            openLogoutWarning();
        });
    });

    cancelBtn.addEventListener('click', closeLogoutWarning);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeLogoutWarning(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLogoutWarning(); });
})();
</script>
<!-- ===== End Log Out Warning Modal ===== -->
</body>
</html>