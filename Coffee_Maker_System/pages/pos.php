<?php
session_start();

if (!isset($_SESSION["admin"])) {
	header("Location: ../index.php");
	exit();
}

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_order") {
	header("Content-Type: application/json; charset=utf-8");
	$payload = json_decode($_POST["order"] ?? "", true);
	$items = is_array($payload["items"] ?? null) ? $payload["items"] : [];

	if (!$items) {
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
		http_response_code(422);
		echo json_encode(["success" => false, "message" => "The order total must be greater than zero."]);
		exit();
	}

	$conn->begin_transaction();
	try {
		$order_statement = $conn->prepare("INSERT INTO orders (order_number, customer_name, total_amount, payment_method, status) VALUES (?, ?, ?, ?, ?)");
		$customer_name = trim($_POST["customer_name"] ?? "") ?: "Walk-in";
		$payment_method = trim($_POST["payment_method"] ?? "") ?: "Cash";
		$status = "Preparing";
		$order_statement->bind_param("ssdss", $order_number, $customer_name, $total_amount, $payment_method, $status);
		$order_statement->execute();
		$order_id = $conn->insert_id;
		$order_statement->close();

		$item_statement = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal, size, addons, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
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
		$item_statement->close();
		$conn->commit();
		echo json_encode(["success" => true, "order_number" => $order_number]);
	} catch (Throwable $error) {
		$conn->rollback();
		http_response_code(500);
		echo json_encode(["success" => false, "message" => "Unable to save the order."]);
	}
	exit();
}

$admin_name = $_SESSION["admin"];
$products = [];

$result = $conn->query("SELECT id, product_name, category, price, addons, image_path FROM products WHERE status != 'Inactive' ORDER BY product_name ASC");

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
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { font-family: Arial, Helvetica, sans-serif; background: #f7f7f7; color: #2b1610; }
		.app-shell { min-height: 100vh; display: flex; }
		.sidebar { width: 245px; min-height: 100vh; background: #2b1610; color: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 30px 18px 20px; position: fixed; inset: 0 auto 0 0; }
		.brand { font-size: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 16px 35px; }
		.nav { display: flex; flex-direction: column; gap: 8px; }
		.nav-item { color: #d5c6c1; text-decoration: none; padding: 14px 16px; border-radius: 4px; font-size: 13px; font-weight: 600; letter-spacing: .5px; }
		.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }
		.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 11px; font-weight: 600; }
		.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; }
		.settings { border: 0; background: transparent; color: #fff; font-size: 18px; cursor: pointer; }
		.content { margin-left: 245px; width: calc(100% - 245px); padding: 42px 35px; }
		.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 23px; }
		.topbar h1 { font-size: 26px; margin-bottom: 6px; }
		.topbar p { color: #858585; font-size: 13px; }
		.pos-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 32px; align-items: start; }
		.toolbar { display: flex; flex-direction: column; gap: 14px; margin-bottom: 25px; }
		.search { width: min(100%, 480px); height: 40px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; padding: 0 14px 0 38px; font-size: 12px; outline: none; }
		.search-wrap { position: relative; width: min(100%, 480px); }
		.search-wrap span { position: absolute; left: 14px; top: 12px; color: #8a8a8a; font-size: 13px; }
		.categories { display: flex; flex-wrap: wrap; gap: 8px; }
		.category { border: 0; border-radius: 16px; padding: 8px 13px; color: #555; background: #ececee; font-size: 11px; cursor: pointer; }
		.category.active, .category:hover { color: #fff; background: #74473b; }
		.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 18px; }
		.product-card { border: 1px solid #ededed; background: #fff; border-radius: 7px; overflow: hidden; cursor: pointer; transition: transform .15s, box-shadow .15s; }
		.product-card:hover { transform: translateY(-2px); box-shadow: 0 5px 14px rgba(43,22,16,.1); }
		.product-image { height: 128px; background: #74473b; display: grid; place-items: center; color: #fff; font-size: 34px; overflow: hidden; } .product-image img { width: 100%; height: 100%; object-fit: cover; }
		.product-info { min-height: 102px; padding: 14px 13px; }
		.product-info h2 { font-size: 13px; line-height: 1.35; margin-bottom: 9px; }
		.product-category { color: #999; font-size: 10px; margin-bottom: 10px; }
		.price { color: #4b2115; font-size: 13px; font-weight: 700; }
		.order-panel { background: #fff; border: 1px solid #bbaaa4; min-height: 318px; display: flex; flex-direction: column; }
		.order-header { display: flex; justify-content: space-between; align-items: center; padding: 17px 15px; border-bottom: 1px solid #eee; font-size: 10px; }
		.order-number { background: #4b413e; color: #fff; padding: 6px 8px; border-radius: 3px; font-size: 9px; }
		.cart { flex: 1; padding: 14px; min-height: 150px; }
		.empty { color: #999; text-align: center; font-size: 12px; padding: 45px 0; }
		.cart-item { display: grid; grid-template-columns: 30px 1fr auto; gap: 10px; align-items: start; margin-bottom: 14px; font-size: 10px; }
		.quantity { background: #74473b; color: #fff; border-radius: 3px; padding: 8px 5px; text-align: center; }
		.cart-item-name { line-height: 1.45; }
		.cart-item-price { white-space: nowrap; }
		.order-footer { border-top: 1px solid #eee; padding: 14px; }
		.total { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 17px; }
		.actions { display: flex; justify-content: flex-end; gap: 10px; }
		.button { border: 1px solid #ded5d1; border-radius: 7px; background: #fff; color: #555; padding: 10px 13px; font-size: 10px; cursor: pointer; }
		.button.confirm { border-color: #241f1d; background: #241f1d; color: #fff; }
		.no-products { color: #999; font-size: 13px; padding: 35px 0; }
		.modal-backdrop { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(32, 22, 19, .68); z-index: 10; }
		.modal-backdrop.open { display: flex; }
		.customizer { width: min(100%, 590px); max-height: 90vh; overflow: auto; border-radius: 12px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); }
		.customizer-header { display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid #eee; }
		.customizer-badge { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 5px; background: #74473b; color: #fff; font-size: 15px; }
		.customizer-title { flex: 1; } .customizer-title strong { display: block; font-size: 13px; } .customizer-title small { color: #8d8581; font-size: 10px; }
		.close-modal { border: 0; border-radius: 50%; width: 27px; height: 27px; background: #f0ecea; color: #61483f; cursor: pointer; }
		.customizer-body { padding: 17px; } .customizer-section { margin-bottom: 17px; } .section-label { display: flex; justify-content: space-between; margin-bottom: 8px; color: #52382f; font-size: 10px; font-weight: 700; }
		.option-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; } .option { position: relative; display: flex; align-items: center; gap: 7px; min-height: 38px; padding: 8px; border: 1px solid #e1d9d5; border-radius: 6px; color: #483832; font-size: 10px; cursor: pointer; } .option.selected { border-color: #9a5f38; box-shadow: inset 0 0 0 1px #9a5f38; } .option input { accent-color: #9a5f38; } .option-price { margin-left: auto; color: #9a5f38; font-size: 9px; font-weight: 700; }
		.notes { width: 100%; min-height: 38px; resize: vertical; border: 1px solid #ded7d4; border-radius: 6px; padding: 10px; font: inherit; font-size: 11px; }
		.customizer-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; background: #faf9f8; } .item-total small { display: block; color: #807570; font-size: 9px; } .item-total strong { font-size: 19px; } .modal-actions { display: flex; gap: 8px; } .quantity-control { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 6px; overflow: hidden; } .quantity-control button { width: 28px; height: 30px; border: 0; background: #fff; cursor: pointer; } .quantity-control span { width: 25px; text-align: center; font-size: 11px; }
		.checkout-modal { width: min(100%, 620px); max-height: 90vh; overflow: auto; border-radius: 10px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); } .checkout-header { padding: 20px 22px; border-bottom: 1px solid #eee; } .checkout-header h2 { font-size: 19px; } .checkout-header p { margin-top: 5px; color: #8d8581; font-size: 11px; } .checkout-items { padding: 8px 22px; } .checkout-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 13px; padding: 14px 0; border-bottom: 1px solid #f0edeb; } .checkout-item strong { display: block; color: #382a25; font-size: 11px; } .checkout-item small { display: block; margin-top: 4px; color: #8d8581; font-size: 10px; } .checkout-price { white-space: nowrap; font-size: 11px; } .checkout-quantity { display: flex; align-items: center; border: 1px solid #ded7d4; border-radius: 5px; overflow: hidden; } .checkout-quantity button { width: 25px; height: 25px; border: 0; background: #fff; color: #54392f; cursor: pointer; } .checkout-quantity span { width: 24px; text-align: center; font-size: 10px; } .remove-item { border: 0; background: transparent; color: #a34a3f; font-size: 10px; cursor: pointer; } .checkout-summary { margin: 0 22px; padding: 15px 0; border-top: 1px solid #e8e2df; } .summary-row { display: flex; justify-content: space-between; margin: 7px 0; color: #766e6a; font-size: 11px; } .summary-row.total-row { margin-top: 14px; color: #2b1610; font-weight: 700; font-size: 14px; } .checkout-footer { display: flex; justify-content: space-between; gap: 10px; padding: 16px 22px; background: #faf9f8; } .checkout-footer .button { min-width: 110px; } .confirmation { text-align: center; padding: 50px 22px 36px; } .confirmation-mark { width: 48px; height: 48px; margin: 0 auto 14px; display: grid; place-items: center; border-radius: 50%; background: #e2f2e5; color: #28763b; font-size: 25px; } .confirmation h2 { font-size: 20px; } .confirmation p { margin-top: 8px; color: #766e6a; font-size: 11px; } .confirmation .button { width: min(100%, 300px); margin-top: 28px; }
		@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .pos-layout { grid-template-columns: 1fr; } .order-panel { max-width: 480px; } }
		@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; padding: 20px; } .brand { padding-bottom: 20px; } .nav { flex-direction: row; flex-wrap: wrap; } .nav-item { padding: 10px; } .sidebar-footer { margin-top: 22px; } .content { margin-left: 0; width: 100%; padding: 22px 16px; } .topbar { align-items: flex-start; flex-direction: column; gap: 8px; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .product-image { height: 100px; } .option-grid { grid-template-columns: 1fr; } .customizer-footer { align-items: flex-start; flex-direction: column; } .modal-actions { width: 100%; justify-content: flex-end; } }
	</style>
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand">COFFEE MAKER</div>
			<nav class="nav">
				<a class="nav-item" href="dashboard.php">DASHBOARD</a>
				<a class="nav-item active" href="pos.php">POS</a>
				<a class="nav-item" href="orders.php">ORDERS</a>
				<a class="nav-item" href="products.php">PRODUCTS</a>
				<a class="nav-item" href="users.php">USERS</a>
			</nav>
		</div>
		<div class="sidebar-footer">
			<div class="user"><div class="avatar">♙</div><span><?= htmlspecialchars($admin_name) ?></span></div>
			<button class="settings" type="button" aria-label="Settings">⚙</button>
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
					<div class="categories"><button class="category active" type="button" data-category="all">All</button><?php foreach ($categories as $category): ?><button class="category" type="button" data-category="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button><?php endforeach; ?></div>
				</div>
				<div class="product-grid" id="productGrid">
					<?php if (count($products) > 0): ?>
						<?php foreach ($products as $product): ?>
							<article class="product-card" data-name="<?= htmlspecialchars(strtolower($product["product_name"])) ?>" data-category="<?= htmlspecialchars($product["category"] ?? "") ?>" data-id="<?= (int) $product["id"] ?>" data-price="<?= htmlspecialchars($product["price"]) ?>" data-product="<?= htmlspecialchars($product["product_name"]) ?>" data-addons='<?= htmlspecialchars($product["addons"] ?? "[]", ENT_QUOTES) ?>'>
								<div class="product-image"><?php if (!empty($product["image_path"])): ?><img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>"><?php else: ?>☕<?php endif; ?></div>
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
		<header class="customizer-header"><div class="customizer-badge">☕</div><div class="customizer-title"><strong id="customizerName">Product Name</strong><small id="customizerDescription">Base Price: ₱0.00</small></div><button class="close-modal" id="closeCustomizer" type="button" aria-label="Close">×</button></header>
		<div class="customizer-body"><div class="customizer-section"><div class="section-label"><span>1. SELECT SIZE <b>*</b></span><small>Required</small></div><div class="option-grid"><label class="option selected"><input type="radio" name="cupSize" value="Regular" data-price="0" checked><span>Regular</span><span class="option-price">₱0.00</span></label><label class="option"><input type="radio" name="cupSize" value="Medium" data-price="25"><span>Medium</span><span class="option-price">+₱25.00</span></label><label class="option"><input type="radio" name="cupSize" value="Large" data-price="35"><span>Large</span><span class="option-price">+₱35.00</span></label></div></div><div class="customizer-section" id="addonSection"><div class="section-label"><span>ADD-ONS &amp; TOPPINGS</span><small id="addonHint">Pick 1</small></div><div class="option-grid" id="addonOptions"></div></div><div class="customizer-section"><div class="section-label"><span>SPECIAL INSTRUCTIONS / NOTES</span></div><textarea class="notes" id="specialNotes" placeholder="e.g., Less drizzle, serve with separate straw..."></textarea></div></div>
		<footer class="customizer-footer"><div class="item-total"><small>ITEM TOTAL CALCULATION</small><strong id="customizerTotal">₱0.00</strong></div><div class="modal-actions"><div class="quantity-control"><button id="decreaseQuantity" type="button">−</button><span id="customizerQuantity">1</span><button id="increaseQuantity" type="button">+</button></div><button class="button" id="cancelCustomizer" type="button">CANCEL</button><button class="button confirm" id="addToOrder" type="button">＋ ADD TO ORDER</button></div></footer>
	</section>
</div>
<div class="modal-backdrop" id="checkoutModal" role="dialog" aria-modal="true" aria-labelledby="checkoutTitle">
	<section class="checkout-modal">
		<div id="checkoutReview"><header class="checkout-header"><h2 id="checkoutTitle">Checkout</h2><p>Review your items and make changes before confirming payment.</p></header><div class="checkout-items" id="checkoutItems"></div><div class="checkout-summary"><div class="summary-row"><span>Subtotal</span><span id="checkoutSubtotal">₱0.00</span></div><div class="summary-row"><span>Tax (0%)</span><span>₱0.00</span></div><div class="summary-row total-row"><span>Total Amount</span><span id="checkoutTotal">₱0.00</span></div></div><footer class="checkout-footer"><button class="button" id="backToPos" type="button">BACK</button><button class="button confirm" id="confirmAndPay" type="button">CONFIRM ORDER &amp; PAY</button></footer></div>
		<div class="confirmation" id="orderConfirmation" hidden><div class="confirmation-mark">✓</div><h2>Order Confirmed</h2><p id="confirmationNumber">Your order has been saved.</p><button class="button confirm" id="doneOrder" type="button">DONE</button></div>
	</section>
</div>
<script>
const cart = new Map();
const currency = value => `₱${value.toFixed(2)}`;
const modal = document.getElementById('customizerModal');
let selectedProduct = null;
let itemQuantity = 1;
function selectedSize() { return document.querySelector('input[name="cupSize"]:checked'); }
function updateCustomizerTotal() {
	const size = selectedSize();
	const addonTotal = [...document.querySelectorAll('#addonOptions input:checked')].reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
	const unitPrice = Number(selectedProduct?.dataset.price || 0) + Number(size?.dataset.price || 0) + addonTotal;
	document.getElementById('customizerTotal').textContent = currency(unitPrice * itemQuantity);
	document.getElementById('customizerQuantity').textContent = itemQuantity;
}
function openCustomizer(card) {
	selectedProduct = card;
	itemQuantity = 1;
	document.getElementById('customizerName').textContent = card.dataset.product;
	document.getElementById('customizerDescription').textContent = `Base Price: ${currency(Number(card.dataset.price))}`;
	document.getElementById('specialNotes').value = '';
	document.querySelectorAll('input[name="cupSize"]').forEach(input => { input.checked = input.value === 'Regular'; input.closest('.option').classList.toggle('selected', input.checked); });
	const addonOptions = document.getElementById('addonOptions');
	let addons = [];
	try { addons = JSON.parse(card.dataset.addons || '[]'); } catch (error) { addons = []; }
	addonOptions.innerHTML = addons.filter(Boolean).map(addon => `<label class="option"><input type="checkbox" value="${escapeHtml(addon)}"><span>${escapeHtml(addon)}</span><span class="option-price">Free</span></label>`).join('');
	document.getElementById('addonSection').hidden = addons.length === 0;
	modal.classList.add('open');
	updateCustomizerTotal();
}
function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character])); }
function closeCustomizer() { modal.classList.remove('open'); selectedProduct = null; }
function renderCart() {
	const cartElement = document.getElementById('cart'); let total = 0;
	if (!cart.size) cartElement.innerHTML = '<p class="empty">Select a menu item to start an order.</p>';
	else cartElement.innerHTML = [...cart.entries()].map(([key, item]) => { total += item.unitPrice * item.quantity; return `<div class="cart-item"><span class="quantity">${item.quantity}x</span><span class="cart-item-name">${escapeHtml(item.name)}<br><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></span><span class="cart-item-price">${currency(item.unitPrice * item.quantity)}<br><button class="remove-item" data-remove="${escapeHtml(key)}" type="button">Remove</button></span></div>`; }).join('');
	document.getElementById('total').textContent = currency(total);
}
function renderCheckout() {
	let total = 0;
	document.getElementById('checkoutItems').innerHTML = [...cart.entries()].map(([key, item]) => { const subtotal = item.unitPrice * item.quantity; total += subtotal; return `<div class="checkout-item"><div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.size)}${item.addons.length ? ` · ${escapeHtml(item.addons.join(', '))}` : ''}${item.notes ? `<br>Note: ${escapeHtml(item.notes)}` : ''}</small></div><div class="checkout-quantity"><button type="button" data-decrease="${escapeHtml(key)}">−</button><span>${item.quantity}</span><button type="button" data-increase="${escapeHtml(key)}">+</button></div><div><div class="checkout-price">${currency(subtotal)}</div><button class="remove-item" type="button" data-remove="${escapeHtml(key)}">Remove</button></div></div>`; }).join('');
	document.getElementById('checkoutSubtotal').textContent = currency(total);
	document.getElementById('checkoutTotal').textContent = currency(total);
}
function openCheckout() { if (!cart.size) { alert('Add at least one item before checking out.'); return; } renderCheckout(); document.getElementById('checkoutReview').hidden = false; document.getElementById('orderConfirmation').hidden = true; document.getElementById('checkoutModal').classList.add('open'); }
function closeCheckout() { document.getElementById('checkoutModal').classList.remove('open'); }
document.querySelectorAll('.product-card').forEach(card => card.addEventListener('click', () => openCustomizer(card)));
document.querySelectorAll('input[name="cupSize"]').forEach(input => input.addEventListener('change', event => { document.querySelectorAll('input[name="cupSize"]').forEach(item => item.closest('.option').classList.toggle('selected', item.checked)); updateCustomizerTotal(); }));
document.getElementById('addonOptions').addEventListener('change', event => { if (event.target.matches('input')) event.target.closest('.option').classList.toggle('selected', event.target.checked); updateCustomizerTotal(); });
document.getElementById('increaseQuantity').addEventListener('click', () => { itemQuantity += 1; updateCustomizerTotal(); });
document.getElementById('decreaseQuantity').addEventListener('click', () => { if (itemQuantity > 1) itemQuantity -= 1; updateCustomizerTotal(); });
document.getElementById('addToOrder').addEventListener('click', () => { const size = selectedSize(); const addons = [...document.querySelectorAll('#addonOptions input:checked')].map(input => input.value); const notes = document.getElementById('specialNotes').value.trim(); const unitPrice = Number(selectedProduct.dataset.price) + Number(size.dataset.price) + addons.reduce((sum, addon) => sum, 0); const key = `${selectedProduct.dataset.id}-${size.value}-${addons.join('|')}-${notes}`; const item = cart.get(key) || { productId: Number(selectedProduct.dataset.id), name: selectedProduct.dataset.product, size: size.value, addons, notes, unitPrice, quantity: 0 }; item.quantity += itemQuantity; cart.set(key, item); renderCart(); closeCustomizer(); });
document.getElementById('cancelOrder').addEventListener('click', () => { cart.clear(); renderCart(); });
document.getElementById('cart').addEventListener('click', event => { const key = event.target.dataset.remove; if (!key) return; cart.delete(key); renderCart(); });
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
	formData.append('order', JSON.stringify({ items: [...cart.values()].map(item => ({ productId: item.productId, quantity: item.quantity, unitPrice: item.unitPrice, size: item.size, addons: item.addons, notes: item.notes })) }));
	try {
		const response = await fetch('pos.php', { method: 'POST', body: formData });
		const result = await response.json();
		if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save the order.');
		document.getElementById('confirmationNumber').textContent = `Order ${result.order_number} has been saved successfully.`;
		document.getElementById('checkoutReview').hidden = true;
		document.getElementById('orderConfirmation').hidden = false;
	} catch (error) { alert(error.message); } finally { button.disabled = false; }
});
document.getElementById('doneOrder').addEventListener('click', () => { cart.clear(); renderCart(); closeCheckout(); });
document.getElementById('closeCustomizer').addEventListener('click', closeCustomizer);
document.getElementById('cancelCustomizer').addEventListener('click', closeCustomizer);
modal.addEventListener('click', event => { if (event.target === modal) closeCustomizer(); });
document.getElementById('productSearch').addEventListener('input', event => { const query = event.target.value.toLowerCase(); document.querySelectorAll('.product-card').forEach(card => { card.hidden = !card.dataset.name.includes(query); }); });
document.querySelectorAll('.category').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.category').forEach(item => item.classList.remove('active')); button.classList.add('active'); const category = button.dataset.category; document.querySelectorAll('.product-card').forEach(card => { card.hidden = category !== 'all' && card.dataset.category !== category; }); }));
</script>
</body>
</html>
