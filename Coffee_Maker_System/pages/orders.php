<?php
session_start();

if (!isset($_SESSION["admin"])) {
	header("Location: ../index.php");
	exit();
}

require_once "../config/database.php";

$admin_name = $_SESSION["admin"];
$search = trim($_GET["search"] ?? "");
$status_filter = trim($_GET["status"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$per_page = 8;
$where = [];
$params = [];
$types = "";

if ($search !== "") {
	$where[] = "(o.order_number LIKE ? OR o.customer_name LIKE ? OR oi.item_names LIKE ?)";
	$term = "%" . $search . "%";
	$params = [$term, $term, $term];
	$types = "sss";
}
if ($status_filter !== "" && in_array($status_filter, ["Preparing", "Completed", "Cancelled"], true)) {
	$where[] = "o.status = ?";
	$params[] = $status_filter;
	$types .= "s";
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$count_sql = "SELECT COUNT(*) AS total FROM orders o LEFT JOIN (SELECT order_id, GROUP_CONCAT(DISTINCT p.product_name SEPARATOR ', ') AS item_names FROM order_items oi2 LEFT JOIN products p ON p.id = oi2.product_id GROUP BY order_id) oi ON oi.order_id = o.id $where_sql";
$count_statement = $conn->prepare($count_sql);
if ($params) $count_statement->bind_param($types, ...$params);
$count_statement->execute();
$total_orders = (int) $count_statement->get_result()->fetch_assoc()["total"];
$count_statement->close();
$total_pages = max(1, (int) ceil($total_orders / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$orders = [];
$query = "SELECT o.order_number, o.customer_name, o.total_amount, o.payment_method, o.status, o.created_at, COALESCE(oi.item_names, 'No items') AS item_names FROM orders o LEFT JOIN (SELECT order_id, GROUP_CONCAT(CONCAT(oi2.quantity, 'x ', COALESCE(p.product_name, 'Product')) SEPARATOR ', ') AS item_names FROM order_items oi2 LEFT JOIN products p ON p.id = oi2.product_id GROUP BY order_id) oi ON oi.order_id = o.id $where_sql ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
$statement = $conn->prepare($query);
$list_types = $types . "ii";
$list_params = array_merge($params, [$per_page, $offset]);
$statement->bind_param($list_types, ...$list_params);
$statement->execute();
$result = $statement->get_result();
while ($row = $result->fetch_assoc()) $orders[] = $row;
$statement->close();

$query_string = http_build_query(["search" => $search, "status" => $status_filter]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Orders | Coffee Maker</title>
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { background: #f7f7f7; color: #2b211e; font-family: Arial, Helvetica, sans-serif; }
		.app-shell { min-height: 100vh; display: flex; }
		.sidebar { width: 224px; min-height: 100vh; padding: 30px 0 20px; background: #2b1610; color: #fff; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
		.brand { padding: 0 28px 34px; font-size: 20px; font-weight: 700; }
		.nav { display: grid; gap: 3px; }
		.nav-item { padding: 13px 28px; color: #d8c8c3; text-decoration: none; font-size: 13px; font-weight: 700; letter-spacing: .5px; }
		.nav-item:hover, .nav-item.active { background: #54392f; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; gap: 10px; border-top: 1px solid #43251e; padding: 16px 28px 0; color: #eee; font-size: 10px; font-weight: 700; }
		.avatar { width: 28px; height: 28px; display: grid; place-items: center; border-radius: 50%; background: #60463e; }
		.content { width: calc(100% - 224px); margin-left: 224px; padding: 67px 36px; }
		.topbar { display: flex; align-items: end; justify-content: space-between; margin-bottom: 25px; }
		h1 { font-size: 24px; }
		.topbar p { margin-top: 7px; color: #8f8986; font-size: 12px; }
		.toolbar { display: flex; justify-content: space-between; gap: 15px; margin-bottom: 20px; }
		.search-form { display: flex; gap: 8px; flex: 1; }
		.search, .filter { height: 38px; border: 1px solid #e5e1df; border-radius: 6px; background: #fff; color: #403532; font-size: 11px; }
		.search { width: min(100%, 360px); padding: 0 13px; }
		.filter { padding: 0 12px; }
		.button { height: 38px; border: 0; border-radius: 6px; padding: 0 16px; background: #2b211e; color: #fff; font-size: 10px; font-weight: 700; cursor: pointer; }
		.button:hover { background: #54392f; }
		.table-card { overflow: hidden; border: 1px solid #eeeae8; border-radius: 8px; background: #fff; }
		.table-wrap { overflow-x: auto; }
		table { width: 100%; min-width: 730px; border-collapse: collapse; }
		th { padding: 13px 15px; background: #fcfbfb; color: #756b67; font-size: 9px; text-align: left; letter-spacing: .4px; }
		td { padding: 15px; border-top: 1px solid #f0edeb; color: #4c4441; font-size: 11px; vertical-align: middle; }
		.order-id { color: #2b211e; font-weight: 700; }
		.items { max-width: 220px; color: #736a67; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.date { color: #766e6a; white-space: nowrap; }
		.badge { display: inline-block; padding: 4px 9px; border-radius: 12px; font-size: 9px; font-weight: 700; }
		.badge.preparing { background: #eeeae8; color: #594943; }
		.badge.completed { background: #e2f2e5; color: #28763b; }
		.badge.cancelled { background: #f9e3e0; color: #a53e35; }
		.empty { padding: 50px 20px; color: #8e8581; text-align: center; font-size: 12px; }
		.card-footer { display: flex; align-items: center; justify-content: space-between; padding: 14px 15px; border-top: 1px solid #f0edeb; color: #766e6a; font-size: 11px; }
		.pagination { display: flex; align-items: center; gap: 5px; }
		.page { min-width: 28px; height: 28px; display: grid; place-items: center; border-radius: 5px; color: #4f4642; text-decoration: none; font-size: 10px; }
		.page.active { background: #2b1610; color: #fff; }
		@media (max-width: 700px) { .sidebar { position: relative; width: 100%; min-height: auto; padding: 22px 0; } .app-shell { display: block; } .brand { padding-bottom: 20px; } .nav { display: flex; flex-wrap: wrap; } .nav-item { padding: 10px 14px; } .sidebar-footer { margin-top: 18px; } .content { width: 100%; margin-left: 0; padding: 30px 16px; } .topbar { align-items: start; flex-direction: column; gap: 5px; } .toolbar { align-items: stretch; flex-direction: column; } .search-form { flex-direction: column; } .search { width: 100%; } }
	</style>
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div><div class="brand">COFFEE MAKER</div><nav class="nav"><a class="nav-item" href="dashboard.php">DASHBOARD</a><a class="nav-item" href="pos.php">POS</a><a class="nav-item active" href="orders.php">ORDERS</a><a class="nav-item" href="products.php">PRODUCTS</a><a class="nav-item" href="users.php">USERS</a></nav></div>
		<div class="sidebar-footer"><span class="avatar">♙</span><span><?= htmlspecialchars($admin_name) ?></span></div>
	</aside>
	<section class="content">
		<header class="topbar"><div><h1>Orders</h1><p><?= date("l, F d, Y") ?></p></div></header>
		<div class="toolbar"><form class="search-form" method="get"><input class="search" name="search" type="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search order ID or customer..." aria-label="Search orders"><select class="filter" name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (["Preparing", "Completed", "Cancelled"] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? "selected" : "" ?>><?= $status ?></option><?php endforeach; ?></select><button class="button" type="submit">FILTER</button></form></div>
		<section class="table-card"><div class="table-wrap"><table><thead><tr><th>ORDER ID</th><th>CUSTOMER</th><th>ITEMS</th><th>DATE / TIME</th><th>TOTAL</th><th>PAYMENT</th><th>STATUS</th></tr></thead><tbody>
		<?php if ($orders): foreach ($orders as $order): ?><tr><td class="order-id">#<?= htmlspecialchars($order["order_number"]) ?></td><td><?= htmlspecialchars($order["customer_name"]) ?></td><td class="items" title="<?= htmlspecialchars($order["item_names"]) ?>"><?= htmlspecialchars($order["item_names"]) ?></td><td class="date"><?= date("M d, Y", strtotime($order["created_at"])) ?><br><?= date("h:i A", strtotime($order["created_at"])) ?></td><td>₱<?= number_format((float) $order["total_amount"], 2) ?></td><td><?= htmlspecialchars($order["payment_method"]) ?></td><td><span class="badge <?= strtolower($order["status"]) ?>"><?= htmlspecialchars($order["status"]) ?></span></td></tr><?php endforeach; else: ?><tr><td class="empty" colspan="7">No orders found.</td></tr><?php endif; ?>
		</tbody></table></div><footer class="card-footer"><span>Showing <?= count($orders) ?> of <?= $total_orders ?> orders</span><nav class="pagination" aria-label="Order pages"><?php if ($page > 1): ?><a class="page" href="?<?= $query_string ?>&page=<?= $page - 1 ?>">&lsaquo;</a><?php endif; ?><?php for ($number = 1; $number <= $total_pages; $number++): ?><a class="page <?= $number === $page ? "active" : "" ?>" href="?<?= $query_string ?>&page=<?= $number ?>"><?= $number ?></a><?php endfor; ?><?php if ($page < $total_pages): ?><a class="page" href="?<?= $query_string ?>&page=<?= $page + 1 ?>">&rsaquo;</a><?php endif; ?></nav></footer></section>
	</section>
</main>
</body>
</html>
