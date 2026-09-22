<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$self_ordering = ($_GET["mode"] ?? "") === "self-order";
if ($self_ordering) {
    $_SESSION["self_ordering"] = true;
}

$is_logged_in = isset($_SESSION["username"]) || isset($_SESSION["admin"]);
if (!$is_logged_in && empty($_SESSION["self_ordering"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

// Handle Order Creation AJAX POST
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_order") {
    ob_start();
    header("Content-Type: application/json; charset=utf-8");

    try {
        $payload = json_decode($_POST["order"] ?? "", true);
        $items = is_array($payload["items"] ?? null) ? $payload["items"] : [];

        if (!$items) {
            ob_clean();
            http_response_code(422);
            echo json_encode(["success" => false, "message" => "Add at least one item before confirming."]);
            exit();
        }

        $order_number = "ORD-" . date("YmdHis") . random_int(10, 99);
        $total_amount = 0;
        foreach ($items as $item) {
            $total_amount += (float) ($item["unitPrice"] ?? 0) * (int) ($item["quantity"] ?? 0);
        }

        if ($total_amount <= 0) {
            ob_clean();
            http_response_code(422);
            echo json_encode(["success" => false, "message" => "The order total must be greater than zero."]);
            exit();
        }

        $conn->begin_transaction();

        $customer_name = trim($_POST["customer_name"] ?? "") ?: "Walk-in";
        $payment_method = trim($_POST["payment_method"] ?? "") ?: "Cash";
        $status = "Preparing";

        // Create main order record
        $order_statement = $conn->prepare("INSERT INTO orders (order_number, customer_name, total_amount, payment_method, status) VALUES (?, ?, ?, ?, ?)");
        if (!$order_statement) {
            $order_statement = $conn->prepare("INSERT INTO orders (order_number, customer_name, total_amount, payment_method) VALUES (?, ?, ?, ?)");
            if (!$order_statement) {
                throw new Exception("Failed to prepare order query: " . $conn->error);
            }
            $order_statement->bind_param("ssds", $order_number, $customer_name, $total_amount, $payment_method);
        } else {
            $order_statement->bind_param("ssdss", $order_number, $customer_name, $total_amount, $payment_method, $status);
        }
        $order_statement->execute();
        $order_id = $conn->insert_id;
        $order_statement->close();

        // Inspect order_items table columns to handle variable schemas dynamically
        $columns_res = $conn->query("SHOW COLUMNS FROM order_items");
        $existing_cols = [];
        if ($columns_res) {
            while ($col = $columns_res->fetch_assoc()) {
                $existing_cols[] = $col['Field'];
            }
        }

        $price_field = in_array('unit_price', $existing_cols) ? 'unit_price' : (in_array('price', $existing_cols) ? 'price' : 'unit_price');
        $has_extra = in_array('size', $existing_cols) && in_array('addons', $existing_cols) && in_array('notes', $existing_cols);
        $has_subtotal = in_array('subtotal', $existing_cols);

        if ($has_extra && $has_subtotal) {
            $item_statement = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, {$price_field}, subtotal, size, addons, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$item_statement) throw new Exception("Failed to prepare item query: " . $conn->error);
            foreach ($items as $item) {
                $product_id = (int) ($item["productId"] ?? 0);
                $quantity = (int) ($item["quantity"] ?? 0);
                $unit_price = (float) ($item["unitPrice"] ?? 0);
                $subtotal = $unit_price * $quantity;
                $size = (string) ($item["size"] ?? "Regular");
                $addons = implode(", ", array_map("strval", is_array($item["addons"] ?? null) ? $item["addons"] : []));
                $notes = trim((string) ($item["notes"] ?? ""));
                $item_statement->bind_param("iiiddsss", $order_id, $product_id, $quantity, $unit_price, $subtotal, $size, $addons, $notes);
                $item_statement->execute();
            }
        } else {
            $item_statement = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, {$price_field}, subtotal) VALUES (?, ?, ?, ?, ?)");
            if (!$item_statement) throw new Exception("Failed to prepare item query: " . $conn->error);
            foreach ($items as $item) {
                $product_id = (int) ($item["productId"] ?? 0);
                $quantity = (int) ($item["quantity"] ?? 0);
                $unit_price = (float) ($item["unitPrice"] ?? 0);
                $subtotal = $unit_price * $quantity;
                $item_statement->bind_param("iiidd", $order_id, $product_id, $quantity, $unit_price, $subtotal);
                $item_statement->execute();
            }
        }

        $item_statement->close();
        $conn->commit();

        ob_clean();
        echo json_encode(["success" => true, "order_number" => $order_number]);
    } catch (Throwable $error) {
        if (isset($conn) && $conn->connect_errno === 0) {
            @$conn->rollback();
        }
        ob_clean();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Unable to save order: " . $error->getMessage()]);
    }
    exit();
}

$user_role = $_SESSION["role"] ?? "admin";
$user_name = $_SESSION["username"] ?? $_SESSION["admin"] ?? "Guest";

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

$products = [];
$result = $conn->query("SELECT id, product_name, category, price, addons, image_path FROM products WHERE status != 'Inactive' OR status IS NULL ORDER BY product_name ASC");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

$categories = [];
foreach ($products as $product) {
    if (!empty($product["category"]) && !in_array($product["category"], $categories, true)) {
        $categories[] = $product["category"];
    }
}
sort($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Coffee Maker POS</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: #f7f7f7; color: #000000; }
		.app-shell { min-height: 100vh; display: flex; }
		.nav-item { padding: 14px 16px; color: #aeb3bd; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; letter-spacing: .5px; transition: .2s; }
		.sidebar { width: 245px; min-height: 100vh; padding: 30px 18px 20px; background: #2B1610; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; display: flex; flex-direction: column; justify-content: space-between; font-family: Arial, Helvetica, sans-serif; }
		.brand { padding: 0 16px 35px; font-size: 20px; font-weight: 700; letter-spacing: 1px; }
		.nav { display: flex; flex-direction: column; gap: 8px; }
		.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }
		
		/* Profile & Popover Styles */
		.user-wrapper { position: relative; flex: 1; }
		.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 12px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; user-select: none; }
		.user:hover { background: #3c2018; }
		.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; font-size: 14px; color: #fff; flex-shrink: 0; }
		.user-details-text { display: flex; flex-direction: column; line-height: 1.25; }
		.user-name { color: #fff; font-size: 13px; font-weight: 600; }
		.user-role { color: #aeb3bd; font-size: 10px; }
		.toggle-icon { margin-left: auto; font-size: 10px; color: #aeb3bd; transition: transform 0.2s; }
		.user.active .toggle-icon { transform: rotate(180deg); }

		.user-popover { position: absolute; bottom: calc(100% + 12px); left: 0; width: 210px; background: #ffffff; color: #20242a; border-radius: 8px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25); padding: 14px; display: none; z-index: 100; animation: popoverFadeIn 0.2s ease; }
		.user-popover.show { display: block; }
		@keyframes popoverFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
		.popover-header { display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
		.popover-avatar { font-size: 28px; color: #74473b; }
		.badge-role { display: inline-block; font-size: 9px; background: #f0ecea; color: #74473b; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-top: 3px; }
		.popover-body { padding: 10px 0; }
		.info-row { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #555; }
		.info-row i { color: #74473b; width: 14px; }
		.popover-footer { padding-top: 10px; border-top: 1px solid #eee; }
		.popover-logout { display: flex; align-items: center; gap: 8px; color: #e74c3c; text-decoration: none; font-size: 12px; font-weight: 600; padding: 6px 8px; border-radius: 5px; transition: background 0.15s; }
		.popover-logout:hover { background: #fdf2f2; }

		.settings { border: 0; background: transparent; color: #fff; font-size: 18px; text-decoration: none; cursor: pointer; display: flex; align-items: center; }
		.content { margin-left: 245px; width: calc(100% - 245px); padding: 35px 40px; color: #20242b; }
		.topbar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
		.topbar h1 { font-size: 28px; font-weight: 700; margin-bottom: 6px; color: #20242b; }
		.topbar p { color: #555d68; font-size: 13px; }
		.pos-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 32px; align-items: start; }
		.toolbar { display: flex; flex-direction: column; gap: 14px; margin-bottom: 25px; }
		.search { width: min(100%, 480px); height: 40px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; padding: 0 14px 0 38px; font-size: 12px; font-family: inherit; outline: none; color: #000000; }
		.search-wrap { position: relative; width: min(100%, 480px); }
		.search-wrap span { position: absolute; left: 14px; top: 12px; color: #000000; font-size: 13px; }
		.categories { display: flex; flex-wrap: wrap; gap: 8px; }
		.category { border: 0; border-radius: 16px; padding: 8px 13px; color: #000000; background: #ececee; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; }
		.category.active, .category:hover { color: #fff; background: #74473b; }
		.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 18px; }
		.product-card { border: 1px solid #ededed; background: #fff; border-radius: 7px; overflow: hidden; cursor: pointer; transition: transform .15s, box-shadow .15s; }
		.product-card:hover { transform: translateY(-2px); box-shadow: 0 5px 14px rgba(43,22,16,.1); }
		.product-image { height: 128px; background: #74473b; display: grid; place-items: center; color: #fff; font-size: 34px; overflow: hidden; } .product-image img { width: 100%; height: 100%; object-fit: cover; }
		.product-info { min-height: 102px; padding: 14px 13px; color: #000000; }
		.product-info h2 { font-size: 13px; line-height: 1.35; margin-bottom: 9px; color: #000000; }
		.product-category { color: #000000; font-size: 10px; margin-bottom: 10px; font-weight: 600; }
		.price { color: #000000; font-size: 13px; font-weight: 700; }
		.order-panel { background: #fff; border: 1px solid #bbaaa4; min-height: 318px; display: flex; flex-direction: column; color: #000000; }
		.order-header { display: flex; justify-content: space-between; align-items: center; padding: 17px 15px; border-bottom: 1px solid #eee; font-size: 10px; color: #000000; font-weight: 700; }
		.order-number { background: #4b413e; color: #fff; padding: 6px 8px; border-radius: 3px; font-size: 9px; }
		.cart { flex: 1; padding: 14px; min-height: 150px; color: #000000; }
		.empty { color: #000000; text-align: center; font-size: 12px; padding: 45px 0; }
		.cart-item { display: grid; grid-template-columns: 30px 1fr auto; gap: 10px; align-items: start; margin-bottom: 14px; font-size: 10px; color: #000000; }
		.quantity { background: #74473b; color: #fff; border-radius: 3px; padding: 8px 5px; text-align: center; }
		.cart-item-name { line-height: 1.45; color: #000000; }
		.cart-item-name small { color: #000000; }
		.cart-item-price { white-space: nowrap; color: #000000; font-weight: 600; }
		.order-footer { border-top: 1px solid #eee; padding: 14px; color: #000000; }
		.total { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 17px; color: #000000; }
		.actions { display: flex; justify-content: flex-end; gap: 10px; }
		.button { border: 1px solid #ded5d1; border-radius: 7px; background: #fff; color: #000000; padding: 10px 13px; font-size: 10px; font-family: inherit; cursor: pointer; font-weight: 600; }
		.button.confirm { border-color: #241f1d; background: #241f1d; color: #fff; }
		.no-products { color: #000000; font-size: 13px; padding: 35px 0; }
		.modal-backdrop { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(32, 22, 19, .68); z-index: 10; }
		.modal-backdrop.open { display: flex; }
		.customizer { width: min(100%, 590px); max-height: 90vh; overflow: auto; border-radius: 12px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); color: #000000; }
		.customizer-header { display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid #eee; }
		.customizer-title { flex: 1; } .customizer-title strong { display: block; font-size: 13px; color: #000000; } .customizer-title small { color: #000000; font-size: 10px; }
		.close-modal { border: 0; border-radius: 50%; width: 27px; height: 27px; background: #f0ecea; color: #000000; cursor: pointer; }
		.customizer-body { padding: 17px; color: #000000; } .customizer-section { margin-bottom: 17px; } .section-label { display: flex; justify-content: space-between; margin-bottom: 8px; color: #000000; font-size: 10px; font-weight: 700; }
		.option-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; } .option { position: relative; display: flex; align-items: center; gap: 7px; min-height: 38px; padding: 8px; border: 1px solid #e1d9d5; border-radius: 6px; color: #000000; font-size: 10px; cursor: pointer; } .option.selected { border-color: #000000; box-shadow: inset 0 0 0 1px #000000; } .option input { accent-color: #000000; } .option-price { margin-left: auto; color: #000000; font-size: 9px; font-weight: 700; }
		.notes { width: 100%; min-height: 38px; resize: vertical; border: 1px solid #ded7d4; border-radius: 6px; padding: 10px; font-family: inherit; font-size: 11px; color: #000000; }
		.customizer-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; background: #faf9f8; } .item-total small { display: block; color: #000000; font-size: 9px; } .item-total strong { font-size: 19px; color: #000000; } .modal-actions { display: flex; gap: 8px; } .quantity-control { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 6px; overflow: hidden; } .quantity-control button { width: 28px; height: 30px; border: 0; background: #fff; cursor: pointer; font-family: inherit; color: #000000; } .quantity-control span { width: 25px; text-align: center; font-size: 11px; color: #000000; }
		.checkout-modal { width: min(100%, 620px); max-height: 90vh; overflow: auto; border-radius: 10px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); color: #000000; } .checkout-header { padding: 20px 22px; border-bottom: 1px solid #eee; } .checkout-header h2 { font-size: 19px; color: #000000; } .checkout-header p { margin-top: 5px; color: #000000; font-size: 11px; } .checkout-items { padding: 8px 22px; } .checkout-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 13px; padding: 14px 0; border-bottom: 1px solid #f0edeb; } .checkout-item strong { display: block; color: #000000; font-size: 11px; } .checkout-item small { display: block; margin-top: 4px; color: #000000; font-size: 10px; } .checkout-price { white-space: nowrap; font-size: 11px; color: #000000; font-weight: 600; } .checkout-quantity { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 5px; overflow: hidden; } .checkout-quantity button { width: 25px; height: 25px; border: 0; background: #fff; color: #000000; cursor: pointer; font-family: inherit; } .checkout-quantity span { width: 24px; text-align: center; font-size: 10px; color: #000000; } .remove-item { border: 0; background: transparent; color: #a34a3f; font-size: 10px; font-family: inherit; cursor: pointer; } .checkout-summary { margin: 0 22px; padding: 15px 0; border-top: 1px solid #e8e2df; } .summary-row { display: flex; justify-content: space-between; margin: 7px 0; color: #000000; font-size: 11px; } .summary-row.total-row { margin-top: 14px; color: #000000; font-weight: 700; font-size: 14px; } .checkout-footer { display: flex; justify-content: space-between; gap: 10px; padding: 16px 22px; background: #faf9f8; } .checkout-footer .button { min-width: 110px; } .confirmation { text-align: center; padding: 50px 22px 36px; color: #000000; } .confirmation-mark { width: 48px; height: 48px; margin: 0 auto 14px; display: grid; place-items: center; border-radius: 50%; background: #e2f2e5; color: #28763b; font-size: 25px; } .confirmation h2 { font-size: 20px; color: #000000; } .confirmation p { margin-top: 8px; color: #000000; font-size: 11px; } .confirmation .button { width: min(100%, 300px); margin-top: 28px; }
		.confirmation-screen { position: fixed; inset: 0; display: grid; place-items: center; padding: 35px; overflow: auto; background: #fbfaf7; z-index: 40; color: #000000; } .confirmation-screen[hidden] { display: none !important; } .confirmation-screen::before { content: none; } .confirmation-layout { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(320px, 1fr) 245px; gap: 45px; align-items: center; width: min(100%, 650px); } .confirmation-main { text-align: center; } .confirmation-screen .confirmation-mark { width: 80px; height: 80px; margin: 0 auto 22px; display: grid; place-items: center; border-radius: 50%; background: #7a3f2a; color: #fff; font-size: 38px; box-shadow: 0 12px 20px rgba(43,22,16,.16); } .confirmation-main h2 { color: #000000; font-size: 22px; } .confirmation-main > p { margin: 9px 0 17px; color: #000000; font-size: 10px; } .confirmation-order-card { padding: 20px 18px; border-radius: 8px; background: #fff; box-shadow: 0 5px 13px rgba(43,22,16,.12); } .confirmation-order-card small { display: block; color: #000000; font-size: 8px; letter-spacing: .7px; } .confirmation-order-card strong { display: block; margin: 8px 0 12px; color: #000000; font-size: 34px; } .confirmation-ready { display: inline-block; padding: 6px 13px; border-radius: 15px; background: #e7e2d4; color: #000000; font-size: 8px; font-weight: 600; } .confirmation-receipt { min-height: 300px; padding: 18px 16px; background: #fff; box-shadow: 0 3px 12px rgba(43,22,16,.06); font-size: 8px; color: #000000; } .receipt-brand { text-align: center; color: #000000; font-size: 12px; font-weight: 700; } .receipt-meta { margin: 10px 0; padding: 8px 0; border-top: 1px dashed #ded7d4; border-bottom: 1px dashed #ded7d4; line-height: 1.6; color: #000000; } .receipt-line { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin: 7px 0; color: #000000; } .receipt-line span:first-child { min-width: 0; max-width: 145px; overflow-wrap: anywhere; } .receipt-line small { display: block; margin-top: 3px; color: #000000; font-size: 7px; font-weight: 700; line-height: 1.4; } .receipt-total { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #ded7d4; font-weight: 700; color: #000000; } .confirmation-done { grid-column: 2; width: 100%; margin-top: -25px; padding: 14px; border: 0; border-radius: 7px; background: #7a3f2a; color: #fff; font-size: 12px; font-family: inherit; cursor: pointer; } .confirmation-note { grid-column: 2; margin-top: -30px; color: #000000; text-align: center; font-size: 8px; }
		@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .pos-layout { grid-template-columns: 1fr; } .order-panel { max-width: 480px; } }
		@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; padding: 20px; } .brand { padding-bottom: 20px; } .nav { flex-direction: row; flex-wrap: wrap; } .nav-item { padding: 10px; } .sidebar-footer { margin-top: 22px; } .content { margin-left: 0; width: 100%; padding: 22px 16px; } .topbar { align-items: flex-start; flex-direction: column; gap: 8px; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .product-image { height: 100px; } .option-grid { grid-template-columns: 1fr; } .customizer-footer { align-items: flex-start; flex-direction: column; } .modal-actions { width: 100%; justify-content: flex-end; } .confirmation-screen { padding: 20px 14px; } .confirmation-layout { grid-template-columns: 1fr; gap: 20px; width: min(100%, 360px); } .confirmation-receipt, .confirmation-done, .confirmation-note { grid-column: 1; } .confirmation-done, .confirmation-note { margin-top: 0; } }
	</style>
	<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand">COFFEE MAKER</div>
			<nav class="nav">
				<?php if ($user_role !== "cashier"): ?>
					<a class="nav-item" href="dashboard.php">DASHBOARD</a>
				<?php endif; ?>
				<a class="nav-item active" href="pos.php">POS</a>
				<a class="nav-item" href="orders.php">ORDERS</a>
				<?php if ($user_role !== "cashier"): ?>
					<a class="nav-item" href="products.php">PRODUCTS</a>
					<a class="nav-item" href="users.php">USERS</a>
				<?php endif; ?>
			</nav>
		</div>
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

	<section class="content">
		<header class="topbar">
			<div><h1>Point of Sale</h1><p><?= date("l, F d, Y") ?></p></div>
		</header>
		<div class="pos-layout">
			<section>
				<div class="toolbar">
					<div class="search-wrap"><span>⌕</span><input class="search" id="productSearch" type="search" placeholder="Search menu items..." aria-label="Search menu items"></div>
					<div class="categories"><button class="category active" type="button" data-category="all">All</button><?php foreach ($categories as$category): ?><button class="category" type="button" data-category="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button><?php endforeach; ?></div>
				</div>
				<div class="product-grid" id="productGrid">
					<?php if (count($products) > 0): ?>
						<?php foreach ($products as$product): ?>
							<article class="product-card" data-name="<?= htmlspecialchars(strtolower($product["product_name"])) ?>" data-category="<?= htmlspecialchars($product["category"] ?? "") ?>" data-id="<?= (int) $product["id"] ?>" data-price="<?= htmlspecialchars($product["price"]) ?>" data-product="<?= htmlspecialchars($product["product_name"]) ?>" data-addons='<?= htmlspecialchars($product["addons"] ?? "[]", ENT_QUOTES) ?>'>
								<div class="product-image"><?php if (!empty($product["image_path"])): ?><img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>"><?php endif; ?></div>
								<div class="product-info"><div class="product-category"><?= htmlspecialchars($product["category"] ?? "Coffee") ?></div><h2><?= htmlspecialchars($product["product_name"]) ?></h2><div class="price">₱<?= number_format((float) $product["price"], 2) ?></div></div>
							</article>
						<?php endforeach; ?>
					<?php else: ?><p class="no-products">No active products found.</p><?php endif; ?>
				</div>
			</section>

			<aside class="order-panel">
				<div class="order-header"><span>CURRENT ORDER</span><span class="order-number">#ORD-<?= rand(100, 999) ?></span></div>
				<div class="cart" id="cart"><p class="empty">Select a menu item to start an order.</p></div>
				<div class="order-footer"><div class="total"><span>Total</span><strong id="total">₱0.00</strong></div><div class="actions"><button class="button" id="cancelOrder" type="button">CANCEL</button><button class="button confirm" id="confirmOrder" type="button">CONFIRM</button></div></div>
			</aside>
		</div>
	</section>
</main>

<div class="modal-backdrop" id="customizerModal" role="dialog" aria-modal="true" aria-labelledby="customizerName">
	<section class="customizer">
		<header class="customizer-header"><div class="customizer-title"><strong id="customizerName">Product Name</strong><small id="customizerDescription">Base Price: ₱0.00</small></div><button class="close-modal" id="closeCustomizer" type="button" aria-label="Close">×</button></header>
		<div class="customizer-body"><div class="customizer-section"><div class="section-label"><span>1. SELECT SIZE <b>*</b></span><small>Required</small></div><div class="option-grid"><label class="option selected"><input type="radio" name="cupSize" value="Regular" data-price="0" checked><span>Regular</span><span class="option-price">₱0.00</span></label><label class="option"><input type="radio" name="cupSize" value="Medium" data-price="25"><span>Medium</span><span class="option-price">+₱25.00</span></label><label class="option"><input type="radio" name="cupSize" value="Large" data-price="35"><span>Large</span><span class="option-price">+₱35.00</span></label></div></div><div class="customizer-section" id="addonSection"><div class="section-label"><span>ADD-ONS &amp; TOPPINGS</span><small id="addonHint">Pick options</small></div><div class="option-grid" id="addonOptions"></div></div><div class="customizer-section"><div class="section-label"><span>SPECIAL INSTRUCTIONS / NOTES</span></div><textarea class="notes" id="specialNotes" placeholder="e.g., Less drizzle, serve with separate straw..."></textarea></div></div>
		<footer class="customizer-footer"><div class="item-total"><small>ITEM TOTAL CALCULATION</small><strong id="customizerTotal">₱0.00</strong></div><div class="modal-actions"><div class="quantity-control"><button id="decreaseQuantity" type="button">−</button><span id="customizerQuantity">1</span><button id="increaseQuantity" type="button">+</button></div><button class="button" id="cancelCustomizer" type="button">CANCEL</button><button class="button confirm" id="addToOrder" type="button">＋ ADD TO ORDER</button></div></footer>
	</section>
</div>

<div class="modal-backdrop" id="checkoutModal" role="dialog" aria-modal="true" aria-labelledby="checkoutTitle">
	<section class="checkout-modal">
		<div id="checkoutReview"><header class="checkout-header"><h2 id="checkoutTitle">Checkout</h2><p>Review your items and make changes before confirming payment.</p></header><div class="checkout-items" id="checkoutItems"></div><div class="checkout-summary"><div class="summary-row"><span>Subtotal</span><span id="checkoutSubtotal">₱0.00</span></div><div class="summary-row"><span>Tax (0%)</span><span>₱0.00</span></div><div class="summary-row total-row"><span>Total Amount</span><span id="checkoutTotal">₱0.00</span></div></div><footer class="checkout-footer"><button class="button" id="backToPos" type="button">BACK</button><button class="button confirm" id="confirmAndPay" type="button">CONFIRM ORDER &amp; PAY</button></footer></div>
		<div class="confirmation-screen" id="orderConfirmation" hidden><div class="confirmation-layout"><section class="confirmation-main"><div class="confirmation-mark">✓</div><h2>Order Confirmed!</h2><p>We’re preparing your order. Please keep your order number ready.</p><div class="confirmation-order-card"><small>YOUR ORDER NUMBER</small><strong id="confirmationNumber">#ORD-0000</strong><span class="confirmation-ready">◷ Ready in approx. 5-7 minutes</span></div></section><aside class="confirmation-receipt"><div class="receipt-brand">COFFEE MAKER</div><div class="receipt-meta"><div>Thank you for your order!</div><div id="receiptDate"></div><div id="receiptOrder"></div></div><div id="receiptItems"></div><div class="receipt-line receipt-total"><span>TOTAL</span><span id="receiptTotal">₱0.00</span></div></aside><button class="confirmation-done" id="doneOrder" type="button">Done</button><p class="confirmation-note">This screen will automatically reset in <span id="confirmationCountdown">10</span> seconds.</p></div></div>
	</section>
</div>

<script>
const cart = new Map();
let cartCounter = 0;
const currency = value => `₱${Number(value || 0).toFixed(2)}`;
const modal = document.getElementById('customizerModal');
let selectedProduct = null;
let itemQuantity = 1;

// Sidebar Profile Popover Toggle
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

function escapeHtml(value) { 
	return String(value || '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character])); 
}

function selectedSize() { 
	return document.querySelector('input[name="cupSize"]:checked'); 
}

function updateCustomizerTotal() {
	const size = selectedSize();
	const sizePrice = size ? Number(size.dataset.price || 0) : 0;
	const addonTotal = [...document.querySelectorAll('#addonOptions input:checked')].reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
	const unitPrice = Number(selectedProduct?.dataset.price || 0) + sizePrice + addonTotal;
	document.getElementById('customizerTotal').textContent = currency(unitPrice * itemQuantity);
	document.getElementById('customizerQuantity').textContent = itemQuantity;
}

function openCustomizer(card) {
	selectedProduct = card;
	itemQuantity = 1;
	document.getElementById('customizerName').textContent = card.dataset.product;
	document.getElementById('customizerDescription').textContent = `Base Price: ${currency(Number(card.dataset.price))}`;
	document.getElementById('specialNotes').value = '';
	document.querySelectorAll('input[name="cupSize"]').forEach(input => { 
		input.checked = input.value === 'Regular'; 
		input.closest('.option').classList.toggle('selected', input.checked); 
	});
	
	const addonOptions = document.getElementById('addonOptions');
	let addons = [];
	try {
		const rawAddons = card.dataset.addons || '[]';
		if (rawAddons.trim().startsWith('[')) {
			addons = JSON.parse(rawAddons);
		} else if (rawAddons) {
			addons = rawAddons.split(',').map(item => item.trim()).filter(Boolean);
		}
	} catch (error) { addons = []; }

	addons = addons.map(addon => typeof addon === 'string' ? { name: addon, price: 0 } : addon);
	addonOptions.innerHTML = addons.map(addon => `<label class="option"><input type="checkbox" value="${escapeHtml(addon.name)}" data-price="${Number(addon.price) || 0}"><span>${escapeHtml(addon.name)}</span><span class="option-price">+${currency(Number(addon.price) || 0)}</span></label>`).join('');
	document.getElementById('addonSection').hidden = addons.length === 0;
	modal.classList.add('open');
	updateCustomizerTotal();
}

function closeCustomizer() { 
	modal.classList.remove('open'); 
	selectedProduct = null; 
}

function renderCart() {
	const cartElement = document.getElementById('cart'); 
	let total = 0;
	if (!cart.size) {
		cartElement.innerHTML = '<p class="empty">Select a menu item to start an order.</p>';
	} else {
		cartElement.innerHTML = [...cart.entries()].map(([key, item]) => { 
			total += item.unitPrice * item.quantity; 
			return `<div class="cart-item"><span class="quantity">${item.quantity}x</span><span class="cart-item-name">${escapeHtml(item.name)}<br><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></span><span class="cart-item-price">${currency(item.unitPrice * item.quantity)}<br><button class="remove-item" data-remove="${escapeHtml(key)}" type="button">Remove</button></span></div>`; 
		}).join('');
	}
	document.getElementById('total').textContent = currency(total);
}

function renderCheckout() {
	let total = 0;
	document.getElementById('checkoutItems').innerHTML = [...cart.entries()].map(([key, item]) => { 
		const subtotal = item.unitPrice * item.quantity; 
		total += subtotal; 
		return `<div class="checkout-item"><div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></div><div class="checkout-quantity"><button type="button" data-decrease="${escapeHtml(key)}">−</button><span>${item.quantity}</span><button type="button" data-increase="${escapeHtml(key)}">+</button></div><div><div class="checkout-price">${currency(subtotal)}</div><button class="remove-item" type="button" data-remove="${escapeHtml(key)}">Remove</button></div></div>`; 
	}).join('');
	document.getElementById('checkoutSubtotal').textContent = currency(total);
	document.getElementById('checkoutTotal').textContent = currency(total);
}

let confirmationTimer;
function renderConfirmation(orderNumber) {
	const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
	document.getElementById('confirmationNumber').textContent = `#${orderNumber}`;
	document.getElementById('receiptOrder').textContent = `Order #: ${orderNumber}`;
	document.getElementById('receiptDate').textContent = new Date().toLocaleString();
	document.getElementById('receiptItems').innerHTML = [...cart.values()].map(item => `<div class="receipt-line"><span>${item.quantity}x ${escapeHtml(item.name)} (${escapeHtml(item.size)})${item.addons.length ? `<br><small>ADD-ONS: ${escapeHtml(item.addons.join(', '))}</small>` : ''}</span><span>${currency(item.unitPrice * item.quantity)}</span></div>`).join('');
	document.getElementById('receiptTotal').textContent = currency(total);
	let seconds = 10;
	document.getElementById('confirmationCountdown').textContent = seconds;
	clearInterval(confirmationTimer);
	confirmationTimer = setInterval(() => { 
		seconds -= 1; 
		document.getElementById('confirmationCountdown').textContent = seconds; 
		if (seconds <= 0) { 
			clearInterval(confirmationTimer); 
			document.getElementById('doneOrder').click(); 
		} 
	}, 1000);
}

function openCheckout() { 
	if (!cart.size) { alert('Add at least one item before checking out.'); return; } 
	renderCheckout(); 
	document.getElementById('checkoutReview').hidden = false; 
	document.getElementById('orderConfirmation').hidden = true; 
	document.getElementById('checkoutModal').classList.add('open'); 
}

function closeCheckout() { 
	document.getElementById('checkoutModal').classList.remove('open'); 
}

document.querySelectorAll('.product-card').forEach(card => card.addEventListener('click', () => openCustomizer(card)));

document.querySelectorAll('input[name="cupSize"]').forEach(input => input.addEventListener('change', () => { 
	document.querySelectorAll('input[name="cupSize"]').forEach(item => item.closest('.option').classList.toggle('selected', item.checked)); 
	updateCustomizerTotal(); 
}));

document.getElementById('addonOptions').addEventListener('change', event => { 
	if (event.target.matches('input')) event.target.closest('.option').classList.toggle('selected', event.target.checked); 
	updateCustomizerTotal(); 
});

document.getElementById('increaseQuantity').addEventListener('click', () => { itemQuantity += 1; updateCustomizerTotal(); });
document.getElementById('decreaseQuantity').addEventListener('click', () => { if (itemQuantity > 1) itemQuantity -= 1; updateCustomizerTotal(); });

document.getElementById('addToOrder').addEventListener('click', () => {
	const sizeInput = selectedSize();
	const sizeVal = sizeInput ? sizeInput.value : 'Regular';
	const sizePrice = sizeInput ? Number(sizeInput.dataset.price || 0) : 0;
	const selectedAddonInputs = [...document.querySelectorAll('#addonOptions input:checked')];
	const addons = selectedAddonInputs.map(input => input.value);
	const notes = document.getElementById('specialNotes').value.trim();
	const basePrice = Number(selectedProduct.dataset.price || 0);
	const addonTotal = selectedAddonInputs.reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
	const unitPrice = basePrice + sizePrice + addonTotal;
	const productId = Number(selectedProduct.dataset.id);

	let existingKey = null;
	for (const [key, item] of cart.entries()) {
		if (item.productId === productId && item.size === sizeVal && JSON.stringify(item.addons) === JSON.stringify(addons) && item.notes === notes) {
			existingKey = key;
			break;
		}
	}

	if (existingKey) {
		cart.get(existingKey).quantity += itemQuantity;
	} else {
		const newKey = `cart_item_${++cartCounter}`;
		cart.set(newKey, {
			productId,
			name: selectedProduct.dataset.product,
			size: sizeVal,
			addons,
			notes,
			unitPrice,
			quantity: itemQuantity
		});
	}

	renderCart();
	closeCustomizer();
});

document.getElementById('cancelOrder').addEventListener('click', () => { cart.clear(); renderCart(); });

document.getElementById('cart').addEventListener('click', event => { 
	const key = event.target.dataset.remove; 
	if (!key || !cart.has(key)) return; 
	cart.delete(key); 
	renderCart(); 
});

document.getElementById('confirmOrder').addEventListener('click', openCheckout);

document.getElementById('checkoutItems').addEventListener('click', event => {
	const key = event.target.dataset.remove || event.target.dataset.increase || event.target.dataset.decrease;
	if (!key || !cart.has(key)) return;
	if (event.target.dataset.remove) cart.delete(key);
	else if (event.target.dataset.increase) cart.get(key).quantity += 1;
	else if (cart.get(key).quantity > 1) cart.get(key).quantity -= 1;
	
	renderCart();
	if (cart.size) renderCheckout(); else closeCheckout();
});

document.getElementById('backToPos').addEventListener('click', closeCheckout);

document.getElementById('confirmAndPay').addEventListener('click', async () => {
	if (!cart.size) { alert('Add at least one item before confirming.'); return; }
	const button = document.getElementById('confirmAndPay');
	button.disabled = true;
	const formData = new FormData();
	formData.append('action', 'create_order');
	formData.append('order', JSON.stringify({ 
		items: [...cart.values()].map(item => ({ 
			productId: item.productId, 
			quantity: item.quantity, 
			unitPrice: item.unitPrice, 
			size: item.size, 
			addons: item.addons, 
			notes: item.notes 
		})) 
	}));
	try {
		const response = await fetch('pos.php', { method: 'POST', body: formData });
		const result = await response.json();
		if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save the order.');
		renderConfirmation(result.order_number);
		document.getElementById('checkoutReview').hidden = true;
		document.getElementById('orderConfirmation').hidden = false;
	} catch (error) { 
		alert(error.message); 
	} finally { 
		button.disabled = false; 
	}
});

document.getElementById('doneOrder').addEventListener('click', () => { 
	clearInterval(confirmationTimer); 
	cart.clear(); 
	renderCart(); 
	closeCheckout(); 
});

document.getElementById('closeCustomizer').addEventListener('click', closeCustomizer);
document.getElementById('cancelCustomizer').addEventListener('click', closeCustomizer);
modal.addEventListener('click', event => { if (event.target === modal) closeCustomizer(); });

document.getElementById('productSearch').addEventListener('input', event => { 
	const query = event.target.value.toLowerCase(); 
	document.querySelectorAll('.product-card').forEach(card => { 
		card.hidden = !card.dataset.name.includes(query); 
	}); 
});

document.querySelectorAll('.category').forEach(button => button.addEventListener('click', () => { 
	document.querySelectorAll('.category').forEach(item => item.classList.remove('active')); 
	button.classList.add('active'); 
	const category = button.dataset.category; 
	document.querySelectorAll('.product-card').forEach(card => { 
		card.hidden = category !== 'all' && card.dataset.category !== category; 
	}); 
}));
</script>
</body>
</html>