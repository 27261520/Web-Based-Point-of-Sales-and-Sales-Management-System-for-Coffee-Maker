```php
<?php

session_start();

if (!isset($_SESSION["admin"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

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


// ADMIN NAME
$admin_name = "ADMIN_01";

if (isset($_SESSION["admin"])) {

    $admin_username = $_SESSION["admin"];

    $stmt = $conn->prepare("
        SELECT full_name, username
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param("s", $admin_username);
        $stmt->execute();

        $admin_result = $stmt->get_result();

        if ($admin_result->num_rows > 0) {

            $admin = $admin_result->fetch_assoc();

            $admin_name = !empty($admin["full_name"])
                ? $admin["full_name"]
                : $admin["username"];
        }

        $stmt->close();
    }
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

<link
href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
rel="stylesheet"
>


<style>

/* =========================================================
   RESET
   ========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Inter", Arial, sans-serif;
    background: #f5f6f8;
    color: #20242b;
}


/* =========================================================
   PAGE LABEL
   ========================================================= */

.page-label {
    display: none;
}


/* =========================================================
   MAIN LAYOUT
   ========================================================= */

.app-shell {
    min-height: 100vh;
    display: flex;
}


/* =========================================================
   SIDEBAR
   ========================================================= */

.sidebar {
    width: 245px;
    min-height: 100vh;
    background: #2B1610;
    color: white;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 30px 18px 20px;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
}


.brand {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 0 16px 35px;
}


.nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
}


.nav-item {
    text-decoration: none;
    color: #aeb3bd;
    padding: 14px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .5px;
    transition: .2s;
}


.nav-item:hover {
    background: #74473b;
    color: white;
}


.nav-item.active {
    background: #74473b;
    color: white;
}


.sidebar-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #292e39;
    padding: 18px 10px 0;
}


.user {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #dfe2e8;
    font-size: 12px;
    font-weight: 600;
}


.avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #303746;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}


.settings {
    background: none;
    border: none;
    color: #aeb3bd;
    font-size: 19px;
    cursor: pointer;
}


/* =========================================================
   CONTENT
   ========================================================= */

.content {
    margin-left: 245px;
    width: calc(100% - 245px);
    padding: 35px 40px;
}


/* =========================================================
   TOPBAR
   ========================================================= */

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 30px;
}


.topbar h1 {
    font-size: 28px;
    margin-bottom: 6px;
}


.topbar p {
    color: #8b9099;
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
    border-radius: 10px;
    padding: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 125px;
}


.eyebrow {
    display: block;
    color: #8b9099;
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
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}


.stat-icon.blue {
    background: #e9f1ff;
}


.stat-icon.green {
    background: #e8f8ee;
}


.stat-icon.orange {
    background: #fff1df;
}


/* =========================================================
   DASHBOARD GRID
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


/* =========================================================
   PANEL
   ========================================================= */

.panel {
    background: white;
    border: 1px solid #e4e6ea;
    border-radius: 10px;
}


.panel h2 {
    font-size: 17px;
    font-weight: 700;
}


/* =========================================================
   SALES OVERVIEW
   ========================================================= */

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
    color: #777d87;
    cursor: pointer;
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
    color: #8b9099;
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
    color: #8b9099;
    font-size: 11px;
}


.view-all {
    border: none;
    background: transparent;
    color: #555d68;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
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
    color: #8b9099;
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
    border-radius: 8px;
    background: #edf1f5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}


.product-icon.warm {
    background: #fff0dd;
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
    color: #8b9099;
    font-size: 10px;
}


.price {
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}


/* =========================================================
   EMPTY DATA
   ========================================================= */

.no-data {
    text-align: center;
    color: #9297a0;
    padding: 25px !important;
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 1000px) {

    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .top-selling {
        width: 100%;
    }

}


@media (max-width: 800px) {

    .sidebar {
        width: 190px;
    }

    .content {
        margin-left: 190px;
        width: calc(100% - 190px);
        padding: 25px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

}


@media (max-width: 600px) {

    .sidebar {
        position: relative;
        width: 100%;
        min-height: auto;
    }

    .app-shell {
        flex-direction: column;
    }

    .content {
        margin-left: 0;
        width: 100%;
    }

    .topbar {
        align-items: flex-start;
        gap: 15px;
        flex-direction: column;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

}

</style>

</head>


<body>


<div class="page-label">
    DASHBOARD
</div>


<main class="app-shell">


<!-- =====================================================
     SIDEBAR
     ===================================================== -->

<aside class="sidebar">

    <div>

        <div class="brand">
            COFFEE MAKER
        </div>


        <nav class="nav">

            <a
                class="nav-item active"
                href="dashboard.php"
            >
                DASHBOARD
            </a>


            <a
                class="nav-item"
                href="pos.php"
            >
                POS
            </a>


            <a
                class="nav-item"
                href="orders.php"
            >
                ORDERS
            </a>


            <a
                class="nav-item"
                href="products.php"
            >
                PRODUCTS
            </a>


            <a
                class="nav-item"
                href="users.php"
            >
                USERS
            </a>

        </nav>

    </div>


    <div class="sidebar-footer">

        <div class="user">

            <div class="avatar">
                ♙
            </div>

            <span>
                <?= htmlspecialchars($admin_name) ?>
            </span>

        </div>


        <button
            class="settings"
            aria-label="Settings"
        >
            ⚙
        </button>

    </div>

</aside>



<!-- =====================================================
     CONTENT
     ===================================================== -->

<section class="content">


<header class="topbar">

    <div>

        <h1>
            Dashboard
        </h1>

        <p>
            <?= date("l, F d, Y") ?>
        </p>

    </div>


    <button
        class="refresh"
        id="refreshBtn"
    >
        ↻&nbsp; Refresh
    </button>

</header>



<!-- =====================================================
     STATISTICS
     ===================================================== -->

<section class="stats-grid">


<article class="stat-card">

    <div>

        <span class="eyebrow">
            TOTAL ORDERS
        </span>

        <strong>
            <?= number_format($total_orders) ?>
        </strong>

    </div>

    <div class="stat-icon blue">
        🛒
    </div>

</article>



<article class="stat-card">

    <div>

        <span class="eyebrow">
            TODAY'S SALES
        </span>

        <strong>
            ₱<?= number_format($today_sales, 2) ?>
        </strong>

    </div>

    <div class="stat-icon green">
        ▣
    </div>

</article>



<article class="stat-card">

    <div>

        <span class="eyebrow">
            TOTAL PRODUCTS
        </span>

        <strong>
            <?= number_format($total_products) ?>
        </strong>

    </div>

    <div class="stat-icon orange">
        ⌛
    </div>

</article>


</section>



<!-- =====================================================
     DASHBOARD GRID
     ===================================================== -->

<section class="dashboard-grid">


<div class="left-column">


<!-- =====================================================
     SALES OVERVIEW
     ===================================================== -->

<article class="panel sales-card">


<div class="panel-header sales-header">

    <h2>
        Sales Overview
    </h2>


    <div
        class="segmented"
        id="rangeTabs"
    >

        <button
            class="selected"
            data-range="today"
        >
            Today
        </button>

        <button
            data-range="week"
        >
            This Week
        </button>

        <button
            data-range="month"
        >
            This Month
        </button>

    </div>

</div>



<div class="chart-wrap">


<div class="y-grid">

    <span></span>
    <span></span>
    <span></span>
    <span></span>

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


<div
    class="bar"
    style="height: <?= $height ?>%;"
    title="₱<?= number_format($day["sales"], 2) ?>"
></div>


<label>
    <?= htmlspecialchars($day["label"]) ?>
</label>


</div>

<?php endforeach; ?>


</div>

</div>

</article>



<!-- =====================================================
     RECENT TRANSACTIONS
     ===================================================== -->

<article class="panel transactions-card">


<div class="panel-header transaction-title-row">

    <h2>
        Recent Transactions
    </h2>


    <span class="date">
        <?= date("F d, Y") ?>
    </span>


    <button
        class="view-all"
        onclick="window.location.href='orders.php'"
    >
        View All
    </button>

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


<td>

<?= htmlspecialchars(
    $transaction["order_number"]
) ?>

</td>



<td>

<?= date(
    "M d, Y",
    strtotime($transaction["created_at"])
) ?>

<br>

<?= date(
    "h:i A",
    strtotime($transaction["created_at"])
) ?>

</td>



<td>

<?= htmlspecialchars(
    !empty($transaction["customer_name"])
    ? $transaction["customer_name"]
    : "Walk-in"
) ?>

</td>



<td>

₱<?= number_format(
    $transaction["total_amount"],
    2
) ?>

</td>



<td>

<?= htmlspecialchars(
    $transaction["payment_method"]
) ?>

</td>



<td>


<?php

$status = strtolower(
    $transaction["status"]
);

$badge_class = "complete";

if (
    $status === "preparing" ||
    $status === "pending"
) {
    $badge_class = "preparing";
}

?>


<span class="badge <?= $badge_class ?>">

<?= htmlspecialchars(
    $transaction["status"]
) ?>

</span>


</td>


</tr>


<?php endforeach; ?>


<?php else: ?>


<tr>

<td
    colspan="6"
    class="no-data"
>
    No transactions found.
</td>

</tr>


<?php endif; ?>


</tbody>


</table>


</div>

</article>


</div>



<!-- =====================================================
     TOP SELLING
     ===================================================== -->

<aside class="panel top-selling">


<h2>
    Top-Selling
</h2>



<?php if (count($top_products) > 0): ?>


<?php foreach ($top_products as $product): ?>


<div class="product-row">


<div class="product-icon">
    ▣
</div>


<div class="product-info">

<strong>
    <?= htmlspecialchars(
        $product["product_name"]
    ) ?>
</strong>

<span>
    <?= number_format(
        $product["total_quantity"]
    ) ?>
    Sold
</span>

</div>


<div class="price">

₱<?= number_format(
    $product["total_sales"],
    2
) ?>

</div>


</div>


<?php endforeach; ?>


<?php else: ?>


<p class="no-data">
    No sales data available.
</p>


<?php endif; ?>


</aside>


</section>


</section>


</main>



<script>

/* =========================================================
   REFRESH BUTTON
   ========================================================= */

document
    .getElementById("refreshBtn")
    .addEventListener("click", function () {

        location.reload();

    });



/* =========================================================
   SALES RANGE BUTTONS
   ========================================================= */

const rangeButtons =
    document.querySelectorAll(
        "#rangeTabs button"
    );


rangeButtons.forEach(function(button) {

    button.addEventListener(
        "click",
        function() {

            rangeButtons.forEach(
                function(btn) {

                    btn.classList.remove(
                        "selected"
                    );

                }
            );


            this.classList.add(
                "selected"
            );

        }
    );

});

</script>


</body>

</html>
```
