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

$user_role = strtolower($_SESSION["role"] ?? "admin");

// Role Guard: Cashier, Admin, or Self-Ordering Mode allowed
if (empty($_SESSION["self_ordering"]) && !in_array($user_role, ["admin", "cashier"], true)) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

// Fetch Store Settings
$settings_query = @$conn->query("SELECT * FROM store_settings WHERE id = 1");
$settings = ($settings_query && $settings_query->num_rows > 0) ? $settings_query->fetch_assoc() : [
    'brand_name' => 'COFFEE MAKER',
    'receipt_header' => 'Thank you for brewing with us!',
    'receipt_footer' => 'Thank you for your order!',
    'social_handle' => 'coffeemaker',
    'show_order_details' => 1,
    'cash_enabled' => 1,
    'gcash_enabled' => 1,
    'maya_enabled' => 1,
    'card_enabled' => 1,
    'gcash_name' => '',
    'gcash_number' => '',
    'maya_name' => '',
    'maya_number' => ''
];

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

// Fetch Active Payment Methods & Account Details
$enabled_payment_methods = [];
if ((int)($settings['cash_enabled'] ?? 1) === 1) $enabled_payment_methods[] = 'Cash';
if ((int)($settings['gcash_enabled'] ?? 0) === 1) $enabled_payment_methods[] = 'GCash';
if ((int)($settings['maya_enabled'] ?? 0) === 1) $enabled_payment_methods[] = 'Maya';
if ((int)($settings['card_enabled'] ?? 0) === 1) $enabled_payment_methods[] = 'Credit / Debit Card';

$payment_details = [
    'gcash_name' => $settings['gcash_name'] ?? '',
    'gcash_number' => $settings['gcash_number'] ?? '',
    'maya_name' => $settings['maya_name'] ?? '',
    'maya_number' => $settings['maya_number'] ?? ''
];

if (empty($enabled_payment_methods)) {
    $enabled_payment_methods[] = 'Cash';
}

// Fetch Categories
$categories = [];
$has_categories_table = false;

$cat_query = @$conn->query("SELECT * FROM categories");
if ($cat_query && $cat_query->num_rows > 0) {
    $has_categories_table = true;
    while ($c_row = $cat_query->fetch_assoc()) {
        $c_status = strtolower(trim($c_row['status'] ?? 'active'));
        if (in_array($c_status, ['inactive', 'deleted', 'disabled', '0'], true)) {
            continue;
        }
        $c_name = trim($c_row['category_name'] ?? $c_row['name'] ?? $c_row['category'] ?? $c_row['title'] ?? reset($c_row) ?? '');
        if ($c_name !== '' && !in_array($c_name, $categories, true)) {
            $categories[] = $c_name;
        }
    }
}

// Fetch Active Products
$products = [];
$result = $conn->query("SELECT id, product_name, category, price, addons, image_path FROM products WHERE status IS NULL OR (LOWER(TRIM(status)) NOT IN ('inactive', 'deleted', 'disabled', '0') AND status != '0') ORDER BY product_name ASC");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['category'] = trim($row['category'] ?? '');
        
        if ($has_categories_table && count($categories) > 0) {
            $cat_exists = false;
            foreach ($categories as $valid_cat) {
                if (strcasecmp($valid_cat, $row['category']) === 0) {
                    $cat_exists = true;
                    $row['category'] = $valid_cat;
                    break;
                }
            }
            if (!$cat_exists) continue;
        } else {
            if ($row['category'] !== '' && !in_array($row['category'], $categories, true)) {
                $categories[] = $row['category'];
            }
        }
        
        $products[] = $row;
    }
}

sort($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= htmlspecialchars($settings['brand_name']) ?> POS</title>
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
		.cart { flex: 1; padding: 14px; min-height: 150px; color: #000000; }
		.empty { color: #000000; text-align: center; font-size: 12px; padding: 45px 0; }
		.cart-item { display: grid; grid-template-columns: 30px 1fr auto; gap: 10px; align-items: start; margin-bottom: 14px; font-size: 10px; color: #000000; }
		.quantity { background: #74473b; color: #fff; border-radius: 3px; padding: 8px 5px; text-align: center; }
		.cart-item-name { line-height: 1.45; color: #000000; }
		.cart-item-name small { color: #000000; }
		.cart-item-price { white-space: nowrap; color: #000000; font-weight: 600; text-align: right; display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
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
		.option-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; } .option { position: relative; display: flex; align-items: center; gap: 7px; min-height: 38px; padding: 8px; border: 1px solid #e1d9d5; border-radius: 6px; color: #000000; font-size: 10px; cursor: pointer; } .option.selected { border-color: #000000; box-shadow: inset 0 0 0 1px #000000; } .option input { accent-color: #000000; margin: 0; flex-shrink: 0; } .option-price { margin-left: auto; color: #000000; font-size: 9px; font-weight: 700; }
		.notes { width: 100%; min-height: 38px; resize: vertical; border: 1px solid #ded7d4; border-radius: 6px; padding: 10px; font-family: inherit; font-size: 11px; color: #000000; }
		.customizer-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; background: #faf9f8; } .item-total small { display: block; color: #000000; font-size: 9px; } .item-total strong { font-size: 19px; color: #000000; } .modal-actions { display: flex; gap: 8px; } .quantity-control { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 6px; overflow: hidden; } .quantity-control button { width: 28px; height: 30px; border: 0; background: #fff; cursor: pointer; font-family: inherit; color: #000000; } .quantity-control span { width: 25px; text-align: center; font-size: 11px; color: #000000; }
		.checkout-modal { width: min(100%, 620px); max-height: 90vh; overflow: auto; border-radius: 10px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); color: #000000; } .checkout-header { padding: 20px 22px; border-bottom: 1px solid #eee; } .checkout-header h2 { font-size: 19px; color: #000000; } .checkout-header p { margin-top: 5px; color: #000000; font-size: 11px; } .checkout-items { padding: 8px 22px; } .checkout-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 13px; padding: 14px 0; border-bottom: 1px solid #f0edeb; } .checkout-item strong { display: block; color: #000000; font-size: 11px; } .checkout-item small { display: block; margin-top: 4px; color: #000000; font-size: 10px; } .checkout-price-wrap { display: flex; align-items: center; justify-content: flex-end; gap: 8px; } .checkout-price { white-space: nowrap; font-size: 11px; color: #000000; font-weight: 600; text-align: right; } .checkout-quantity { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 5px; overflow: hidden; } .checkout-quantity button { width: 25px; height: 25px; border: 0; background: #fff; color: #000000; cursor: pointer; font-family: inherit; } .checkout-quantity span { width: 24px; text-align: center; font-size: 10px; color: #000000; } 
		
		/* Dynamic Payment Methods Section in Checkout */
		.checkout-payment-section { margin: 15px 22px 0; padding-top: 15px; border-top: 1px solid #e8e2df; }
		.checkout-payment-section label.section-title { display: block; font-size: 10px; font-weight: 700; color: #000000; margin-bottom: 10px; letter-spacing: 0.5px; }
		.checkout-payment-methods { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; }
		
		.payment-method-option { 
			display: flex; 
			align-items: center; 
			justify-content: flex-start;
			gap: 8px; 
			padding: 10px 14px; 
			border: 1px solid #ded7d4; 
			border-radius: 6px; 
			font-size: 11px; 
			font-weight: 600; 
			cursor: pointer; 
			color: #000000; 
			transition: all 0.15s ease; 
			user-select: none; 
			height: 42px;
		}
		.payment-method-option:hover { 
			border-color: #74473b; 
			background: #faf8f7; 
		}
		.payment-method-option.selected { 
			border-color: #241f1d; 
			background: #fdfaf9; 
			box-shadow: inset 0 0 0 1px #241f1d; 
		}
		.payment-method-option input[type="radio"] { 
			margin: 0; 
			width: 15px; 
			height: 15px; 
			flex-shrink: 0; 
			accent-color: #241f1d; 
			cursor: pointer; 
			vertical-align: middle;
		}
		.payment-method-option span { 
			display: inline-flex; 
			align-items: center; 
			line-height: 1; 
			color: #000000;
			font-size: 11px;
			font-weight: 600;
		}

		/* Cash Payment Section */
		.cash-payment-container {
			margin-top: 15px;
			padding: 16px;
			background: #faf9f8;
			border: 1px solid #e8e2df;
			border-radius: 8px;
			display: flex;
			flex-direction: column;
			gap: 12px;
		}
		.cash-row {
			display: flex;
			gap: 12px;
		}
		.cash-col {
			flex: 1;
		}
		.cash-col label {
			display: block;
			font-size: 10px;
			font-weight: 700;
			color: #000000;
			margin-bottom: 6px;
			letter-spacing: 0.5px;
		}
		.cash-input {
			width: 100%;
			height: 40px;
			padding: 0 12px;
			border: 1px solid #ded7d4;
			border-radius: 6px;
			font-size: 14px;
			font-weight: 700;
			color: #000000;
			outline: none;
			background: #fff;
			font-family: inherit;
		}
		.cash-input:focus {
			border-color: #74473b;
			box-shadow: inset 0 0 0 1px #74473b;
		}
		.change-display {
			height: 40px;
			padding: 0 12px;
			background: #fff;
			border: 1px solid #ded7d4;
			border-radius: 6px;
			font-size: 14px;
			font-weight: 700;
			color: #28763b;
			display: flex;
			align-items: center;
		}
		.quick-cash-buttons {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
		}
		.btn-quick-cash {
			padding: 6px 10px;
			font-size: 10px;
			font-weight: 600;
			border: 1px solid #ded7d4;
			border-radius: 5px;
			background: #fff;
			color: #000;
			cursor: pointer;
			transition: background 0.15s;
		}
		.btn-quick-cash:hover {
			background: #f0ecea;
			border-color: #74473b;
		}

		/* Dynamic Placeholder QR Code Display Box */
		.qr-code-container { margin-top: 15px; padding: 16px; border: 1px dashed #74473b; border-radius: 8px; background: #fdfaf9; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 10px; animation: qrFadeIn 0.2s ease-in-out; }
		@keyframes qrFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
		.qr-code-container img { width: 150px; height: 150px; border-radius: 6px; border: 1px solid #e8e2df; padding: 6px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
		.qr-title { font-size: 11px; font-weight: 700; color: #241f1d; }
		.qr-account-info { font-size: 11px; color: #2b1610; line-height: 1.5; background: #f4ece9; padding: 8px 12px; border-radius: 6px; width: 100%; max-width: 260px; text-align: center; border: 1px solid #e5dcd8; }
		.qr-account-info strong { color: #74473b; font-weight: 700; }
		.qr-instructions { font-size: 10px; color: #555; line-height: 1.5; }
		.qr-ref { font-size: 10px; font-family: monospace; background: #efebe9; padding: 2px 6px; border-radius: 4px; color: #333; font-weight: 600; }

		/* Remove Button */
		.remove-item {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 22px;
			height: 22px;
			border: none;
			border-radius: 50%;
			background: transparent;
			color: #a0a5ad;
			font-size: 11px;
			cursor: pointer;
			padding: 0;
			margin-left: 2px;
			transition: all 0.2s ease-in-out;
			outline: none;
		}
		.remove-item:hover {
			background: #f5ebe8;
			color: #9a3b2b;
			transform: scale(1.1) rotate(90deg);
		}
		.remove-item:active {
			transform: scale(0.95);
		}

		.checkout-summary { margin: 0 22px; padding: 15px 0; border-top: 1px solid #e8e2df; } .summary-row { display: flex; justify-content: space-between; margin: 7px 0; color: #000000; font-size: 11px; } .summary-row.total-row { margin-top: 14px; color: #000000; font-weight: 700; font-size: 14px; } .checkout-footer { display: flex; justify-content: space-between; gap: 10px; padding: 16px 22px; background: #faf9f8; } .checkout-footer .button { min-width: 110px; } .confirmation { text-align: center; padding: 50px 22px 36px; color: #000000; } .confirmation-mark { width: 48px; height: 48px; margin: 0 auto 14px; display: grid; place-items: center; border-radius: 50%; background: #e2f2e5; color: #28763b; font-size: 25px; } .confirmation h2 { font-size: 20px; color: #000000; } .confirmation p { margin-top: 8px; color: #000000; font-size: 11px; } .confirmation .button { width: min(100%, 300px); margin-top: 28px; }
		.confirmation-screen { position: fixed; inset: 0; display: grid; place-items: center; padding: 35px; overflow: auto; background: #fbfaf7; z-index: 40; color: #000000; } .confirmation-screen[hidden] { display: none !important; } .confirmation-screen::before { content: none; } .confirmation-layout { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(320px, 1fr) 245px; gap: 45px; align-items: center; width: min(100%, 650px); } .confirmation-main { text-align: center; } .confirmation-screen .confirmation-mark { width: 80px; height: 80px; margin: 0 auto 22px; display: grid; place-items: center; border-radius: 50%; background: #7a3f2a; color: #fff; font-size: 38px; box-shadow: 0 12px 20px rgba(43,22,16,.16); } .confirmation-main h2 { color: #000000; font-size: 22px; } .confirmation-main > p { margin: 9px 0 17px; color: #000000; font-size: 10px; } .confirmation-order-card { padding: 20px 18px; border-radius: 8px; background: #fff; box-shadow: 0 5px 13px rgba(43,22,16,.12); } .confirmation-order-card small { display: block; color: #000000; font-size: 8px; letter-spacing: .7px; } .confirmation-order-card strong { display: block; margin: 8px 0 12px; color: #000000; font-size: 34px; } .confirmation-ready { display: inline-block; padding: 6px 13px; border-radius: 15px; background: #e7e2d4; color: #000000; font-size: 8px; font-weight: 600; } 
		
		/* Receipt Styles Connected to Settings */
		.confirmation-receipt { min-height: 300px; padding: 18px 16px; background: #fff; box-shadow: 0 3px 12px rgba(43,22,16,.06); font-size: 8px; color: #000000; } 
		.receipt-brand { text-align: center; color: #000000; font-size: 12px; font-weight: 700; } 
		.receipt-header-msg { text-align: center; margin-top: 4px; font-size: 8px; color: #555; }
		.receipt-meta { margin: 10px 0; padding: 8px 0; border-top: 1px dashed #ded7d4; border-bottom: 1px dashed #ded7d4; line-height: 1.6; color: #000000; } 
		.receipt-line { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin: 7px 0; color: #000000; } 
		.receipt-line span:first-child { min-width: 0; max-width: 145px; overflow-wrap: anywhere; } 
		.receipt-line small { display: block; margin-top: 3px; color: #000000; font-size: 7px; font-weight: 700; line-height: 1.4; } 
		.receipt-total { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #ded7d4; font-weight: 700; color: #000000; } 
		.receipt-footer-msg { text-align: center; margin-top: 12px; font-size: 8px; color: #555; white-space: pre-line; }
		.receipt-social { text-align: center; margin-top: 6px; font-size: 7px; color: #777; font-weight: 600; }
		.confirmation-done { grid-column: 2; width: 100%; margin-top: -25px; padding: 14px; border: 0; border-radius: 7px; background: #7a3f2a; color: #fff; font-size: 12px; font-family: inherit; cursor: pointer; } 
		.confirmation-note { grid-column: 2; margin-top: -30px; color: #000000; text-align: center; font-size: 8px; }
		
		@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .pos-layout { grid-template-columns: 1fr; } .order-panel { max-width: 480px; } }
		@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; padding: 20px; } .brand { padding-bottom: 20px; } .nav { flex-direction: row; flex-wrap: wrap; } .nav-item { padding: 10px; } .sidebar-footer { margin-top: 22px; } .content { margin-left: 0; width: 100%; padding: 22px 16px; } .topbar { align-items: flex-start; flex-direction: column; gap: 8px; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .product-image { height: 100px; } .option-grid { grid-template-columns: 1fr; } .customizer-footer { align-items: flex-start; flex-direction: column; } .modal-actions { width: 100%; justify-content: flex-end; } .confirmation-screen { padding: 20px 14px; } .confirmation-layout { grid-template-columns: 1fr; gap: 20px; width: min(100%, 360px); } .confirmation-receipt, .confirmation-done, .confirmation-note { grid-column: 1; } .confirmation-done, .confirmation-note { margin-top: 0; } }
	</style>
	<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand"><?= htmlspecialchars($settings['brand_name']) ?></div>
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

	<section class="content">
		<header class="topbar">
			<div><h1>Point of Sale</h1><p><?= date("l, F d, Y") ?></p></div>
		</header>
		<div class="pos-layout">
			<section>
				<div class="toolbar">
					<div class="search-wrap"><span>⌕</span><input class="search" id="productSearch" type="search" placeholder="Search menu items..." aria-label="Search menu items"></div>
					<div class="categories">
						<button class="category active" type="button" data-category="all">All</button>
						<?php foreach ($categories as $category): ?>
							<button class="category" type="button" data-category="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="product-grid" id="productGrid">
					<?php if (count($products) > 0): ?>
						<?php foreach ($products as $product): ?>
							<article class="product-card" data-name="<?= htmlspecialchars(strtolower($product["product_name"])) ?>" data-category="<?= htmlspecialchars($product["category"] ?? "") ?>" data-id="<?= (int) $product["id"] ?>" data-price="<?= htmlspecialchars($product["price"]) ?>" data-product="<?= htmlspecialchars($product["product_name"]) ?>" data-addons='<?= htmlspecialchars($product["addons"] ?? "[]", ENT_QUOTES) ?>'>
								<div class="product-image"><?php if (!empty($product["image_path"])): ?><img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>"><?php endif; ?></div>
								<div class="product-info"><div class="product-category"><?= htmlspecialchars($product["category"] ?: "Coffee") ?></div><h2><?= htmlspecialchars($product["product_name"]) ?></h2><div class="price">₱<?= number_format((float) $product["price"], 2) ?></div></div>
							</article>
						<?php endforeach; ?>
					<?php else: ?><p class="no-products">No active products found.</p><?php endif; ?>
				</div>
			</section>

			<aside class="order-panel">
				<div class="order-header"><span>CURRENT ORDER</span></div>
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
		<div id="checkoutReview">
			<header class="checkout-header"><h2 id="checkoutTitle">Checkout</h2><p>Review your items and make changes before confirming payment.</p></header>
			<div class="checkout-items" id="checkoutItems"></div>
			
			<div class="checkout-payment-section">
				<label class="section-title">PAYMENT METHOD</label>
				<div class="checkout-payment-methods">
					<?php foreach ($enabled_payment_methods as $index => $method): ?>
						<label class="payment-method-option <?= $index === 0 ? 'selected' : '' ?>">
							<input type="radio" name="payment_method_option" value="<?= htmlspecialchars($method) ?>" <?= $index === 0 ? 'checked' : '' ?>>
							<span><?= htmlspecialchars($method) ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<!-- Cash Payment Input Section -->
				<div class="cash-payment-container" id="cashPaymentSection">
					<div class="cash-row">
						<div class="cash-col">
							<label for="cashTendered">CASH RECEIVED (₱)</label>
							<input type="number" id="cashTendered" class="cash-input" step="0.01" min="0" placeholder="0.00">
						</div>
						<div class="cash-col">
							<label>CHANGE</label>
							<div class="change-display" id="cashChange">₱0.00</div>
						</div>
					</div>
					<div class="quick-cash-buttons" id="quickCashButtons">
						<button type="button" class="btn-quick-cash" data-cash="exact">Exact</button>
						<button type="button" class="btn-quick-cash" data-cash="100">₱100</button>
						<button type="button" class="btn-quick-cash" data-cash="200">₱200</button>
						<button type="button" class="btn-quick-cash" data-cash="500">₱500</button>
						<button type="button" class="btn-quick-cash" data-cash="1000">₱1,000</button>
					</div>
				</div>

				<div class="qr-code-container" id="qrContainer" style="display: none;">
					<div class="qr-title">Scan QR Code via <span id="qrMethodName">GCash</span></div>
					<img id="qrCodeImg" src="" alt="Payment QR Code">
					<div class="qr-account-info" id="qrAccountInfo" style="display: none;"></div>

					<div class="qr-instructions">
						Total to Pay: <strong id="qrAmount">₱0.00</strong><br>
						<small>Ref #: <span class="qr-ref" id="qrRefNum">REF-123456</span></small>
					</div>
				</div>
			</div>

			<div class="checkout-summary"><div class="summary-row"><span>Subtotal</span><span id="checkoutSubtotal">₱0.00</span></div><div class="summary-row"><span>Tax (0%)</span><span>₱0.00</span></div><div class="summary-row total-row"><span>Total Amount</span><span id="checkoutTotal">₱0.00</span></div></div>
			<footer class="checkout-footer"><button class="button" id="backToPos" type="button">BACK</button><button class="button confirm" id="confirmAndPay" type="button">CONFIRM ORDER &amp; PAY</button></footer>
		</div>

		<!-- Completion Receipt Connected to Settings -->
		<div class="confirmation-screen" id="orderConfirmation" hidden>
			<div class="confirmation-layout">
				<section class="confirmation-main">
					<div class="confirmation-mark">✓</div>
					<h2>Order Confirmed!</h2>
					<p>We’re preparing your order. Please keep your order number ready.</p>
					<div class="confirmation-order-card">
						<small>YOUR ORDER NUMBER</small>
						<strong id="confirmationNumber">#ORD-0000</strong>
						<span class="confirmation-ready">◷ Ready in approx. 5-7 minutes</span>
					</div>
				</section>
				<aside class="confirmation-receipt">
					<div class="receipt-brand"><?= htmlspecialchars($settings['brand_name']) ?></div>
					<?php if (!empty($settings['receipt_header'])): ?>
						<div class="receipt-header-msg"><?= htmlspecialchars($settings['receipt_header']) ?></div>
					<?php endif; ?>
					
					<div class="receipt-meta">
						<?php if (!empty($settings['show_order_details'])): ?>
							<div id="receiptDate"></div>
						<?php endif; ?>
						<div id="receiptOrder"></div>
						<div id="receiptPayment"></div>
					</div>
					
					<div id="receiptItems"></div>
					<div class="receipt-line receipt-total">
						<span>TOTAL</span>
						<span id="receiptTotal">₱0.00</span>
					</div>

					<?php if (!empty($settings['receipt_footer'])): ?>
						<div class="receipt-footer-msg"><?= htmlspecialchars($settings['receipt_footer']) ?></div>
					<?php endif; ?>

					<?php if (!empty($settings['social_handle'])): ?>
						<div class="receipt-social">@<?= htmlspecialchars(ltrim($settings['social_handle'], '@')) ?></div>
					<?php endif; ?>
				</aside>
				<button class="confirmation-done" id="doneOrder" type="button">Done</button>
				<p class="confirmation-note">This screen will automatically reset in <span id="confirmationCountdown">10</span> seconds.</p>
			</div>
		</div>
	</section>
</div>

<script>
const paymentDetails = <?= json_encode($payment_details) ?>;
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
			return `<div class="cart-item"><span class="quantity">${item.quantity}x</span><span class="cart-item-name">${escapeHtml(item.name)}<br><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></span><span class="cart-item-price">${currency(item.unitPrice * item.quantity)}<button class="remove-item" data-remove="${escapeHtml(key)}" type="button" aria-label="Remove"><i class="fas fa-times"></i></button></span></div>`; 
		}).join('');
	}
	document.getElementById('total').textContent = currency(total);
}

function calculateChange() {
	const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
	const cashInput = document.getElementById('cashTendered');
	const changeDisplay = document.getElementById('cashChange');
	const tendered = parseFloat(cashInput.value) || 0;
	
	const change = tendered - total;
	if (tendered === 0) {
		changeDisplay.textContent = '₱0.00';
		changeDisplay.style.color = '#28763b';
	} else if (change < 0) {
		changeDisplay.textContent = 'Insufficient Cash';
		changeDisplay.style.color = '#d9534f';
	} else {
		changeDisplay.textContent = currency(change);
		changeDisplay.style.color = '#28763b';
	}
}

function updateQrDisplay() {
	const selectedPaymentOption = document.querySelector('input[name="payment_method_option"]:checked');
	const paymentMethod = selectedPaymentOption ? selectedPaymentOption.value : 'Cash';
	const qrContainer = document.getElementById('qrContainer');
	const cashSection = document.getElementById('cashPaymentSection');
	const qrAccountInfo = document.getElementById('qrAccountInfo');
	
	if (paymentMethod.toLowerCase() === 'cash') {
		qrContainer.style.display = 'none';
		cashSection.style.display = 'flex';
		calculateChange();
	} else {
		cashSection.style.display = 'none';
		if (paymentMethod.toLowerCase().includes('card')) {
			qrContainer.style.display = 'none';
		} else {
			qrContainer.style.display = 'flex';
			document.getElementById('qrMethodName').textContent = paymentMethod;
			
			let accName = '';
			let accNum = '';

			if (paymentMethod.toLowerCase() === 'gcash') {
				accName = paymentDetails.gcash_name;
				accNum = paymentDetails.gcash_number;
			} else if (paymentMethod.toLowerCase() === 'maya') {
				accName = paymentDetails.maya_name;
				accNum = paymentDetails.maya_number;
			}

			if (qrAccountInfo) {
				let infoHtml = '';
				if (accName) infoHtml += `<div>Name: <strong>${escapeHtml(accName)}</strong></div>`;
				if (accNum) infoHtml += `<div>Account No.: <strong>${escapeHtml(accNum)}</strong></div>`;
				
				if (infoHtml !== '') {
					qrAccountInfo.innerHTML = infoHtml;
					qrAccountInfo.style.display = 'block';
				} else {
					qrAccountInfo.style.display = 'none';
				}
			}
			
			const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
			document.getElementById('qrAmount').textContent = currency(total);
			
			if (!qrContainer.dataset.ref || qrContainer.dataset.currentMethod !== paymentMethod) {
				const randomRef = 'REF-' + Math.floor(100000 + Math.random() * 900000);
				document.getElementById('qrRefNum').textContent = randomRef;
				qrContainer.dataset.ref = randomRef;
				qrContainer.dataset.currentMethod = paymentMethod;
			}
			
			const refNum = document.getElementById('qrRefNum').textContent;
			const qrData = encodeURIComponent(`PAYMENT:${paymentMethod}|NAME:${accName}|NUM:${accNum}|AMOUNT:${total.toFixed(2)}|REF:${refNum}`);
			document.getElementById('qrCodeImg').src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${qrData}`;
		}
	}
}

function renderCheckout() {
	let total = 0;
	document.getElementById('checkoutItems').innerHTML = [...cart.entries()].map(([key, item]) => { 
		const subtotal = item.unitPrice * item.quantity; 
		total += subtotal; 
		return `<div class="checkout-item"><div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></div><div class="checkout-quantity"><button type="button" data-decrease="${escapeHtml(key)}">−</button><span>${item.quantity}</span><button type="button" data-increase="${escapeHtml(key)}">+</button></div><div class="checkout-price-wrap"><span class="checkout-price">${currency(subtotal)}</span><button class="remove-item" type="button" data-remove="${escapeHtml(key)}" aria-label="Remove"><i class="fas fa-times"></i></button></div></div>`; 
	}).join('');
	document.getElementById('checkoutSubtotal').textContent = currency(total);
	document.getElementById('checkoutTotal').textContent = currency(total);
	document.getElementById('cashTendered').value = '';
	updateQrDisplay();
}

let confirmationTimer;
function renderConfirmation(orderNumber, paymentMethod) {
	const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
	document.getElementById('confirmationNumber').textContent = `#${orderNumber}`;
	document.getElementById('receiptOrder').textContent = `Order #: ${orderNumber}`;
	
	if (paymentMethod.toLowerCase() === 'cash') {
		const tendered = parseFloat(document.getElementById('cashTendered').value) || total;
		const change = tendered - total;
		document.getElementById('receiptPayment').innerHTML = `Payment: Cash<br>Tendered: ${currency(tendered)}<br>Change: ${currency(change)}`;
	} else {
		document.getElementById('receiptPayment').textContent = `Payment: ${escapeHtml(paymentMethod)}`;
	}

	if (document.getElementById('receiptDate')) {
		document.getElementById('receiptDate').textContent = new Date().toLocaleString();
	}
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

document.querySelectorAll('input[name="payment_method_option"]').forEach(radio => {
	radio.addEventListener('change', (e) => {
		document.querySelectorAll('.payment-method-option').forEach(opt => opt.classList.remove('selected'));
		e.target.closest('.payment-method-option').classList.add('selected');
		updateQrDisplay();
	});
});

document.getElementById('cashTendered').addEventListener('input', calculateChange);

document.getElementById('quickCashButtons').addEventListener('click', (e) => {
	const btn = e.target.closest('button[data-cash]');
	if (!btn) return;
	
	const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
	const value = btn.dataset.cash;
	
	if (value === 'exact') {
		document.getElementById('cashTendered').value = total.toFixed(2);
	} else {
		document.getElementById('cashTendered').value = parseFloat(value).toFixed(2);
	}
	calculateChange();
});

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
	const btn = event.target.closest('.remove-item');
	if (!btn) return;
	const key = btn.dataset.remove; 
	if (!key || !cart.has(key)) return; 
	cart.delete(key); 
	renderCart(); 
});

document.getElementById('confirmOrder').addEventListener('click', openCheckout);

document.getElementById('checkoutItems').addEventListener('click', event => {
	const removeBtn = event.target.closest('.remove-item');
	const key = removeBtn ? removeBtn.dataset.remove : (event.target.dataset.increase || event.target.dataset.decrease);
	if (!key || !cart.has(key)) return;
	if (removeBtn) cart.delete(key);
	else if (event.target.dataset.increase) cart.get(key).quantity += 1;
	else if (cart.get(key).quantity > 1) cart.get(key).quantity -= 1;
	
	renderCart();
	if (cart.size) renderCheckout(); else closeCheckout();
});

document.getElementById('backToPos').addEventListener('click', closeCheckout);

document.getElementById('confirmAndPay').addEventListener('click', async () => {
	if (!cart.size) { alert('Add at least one item before confirming.'); return; }
	
	const selectedPaymentOption = document.querySelector('input[name="payment_method_option"]:checked');
	const paymentMethod = selectedPaymentOption ? selectedPaymentOption.value : 'Cash';

	// Validate Cash Payment
	if (paymentMethod.toLowerCase() === 'cash') {
		const total = [...cart.values()].reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
		const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
		if (tendered < total) {
			alert('Cash tendered is less than the total amount due.');
			return;
		}
	}

	const button = document.getElementById('confirmAndPay');
	button.disabled = true;
	const formData = new FormData();
	formData.append('action', 'create_order');
	formData.append('payment_method', paymentMethod);
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
		renderConfirmation(result.order_number, paymentMethod);
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

// Search filtering
document.getElementById('productSearch').addEventListener('input', event => { 
	const query = event.target.value.toLowerCase().trim();
	document.querySelectorAll('.product-card').forEach(card => {
		const name = (card.dataset.name || '').toLowerCase();
		card.style.display = name.includes(query) ? '' : 'none';
	});
});

// Category filtering
document.querySelectorAll('.categories .category').forEach(btn => {
	btn.addEventListener('click', () => {
		document.querySelectorAll('.categories .category').forEach(b => b.classList.remove('active'));
		btn.classList.add('active');
		const cat = (btn.dataset.category || '').toLowerCase().trim();
		document.querySelectorAll('.product-card').forEach(card => {
			const cardCat = (card.dataset.category || '').toLowerCase().trim();
			card.style.display = (cat === 'all' || cardCat === cat) ? '' : 'none';
		});
	});
});
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