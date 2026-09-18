<?php
session_start();
require_once "../config/database.php";

$products = [];
$result =$conn->query("SELECT id, product_name, category, price, addons, description, image_path FROM products WHERE status != 'Inactive' ORDER BY category, product_name");
if ($result) {
    while ($row =$result->fetch_assoc()) {
        $products[] =$row;
    }
}
$categories = [];
foreach ($products as$product) {
    if ($product["category"] !== "" && !in_array($product["category"], $categories, true)) {
        $categories[] =$product["category"];
    }
}
sort($categories);
function self_order_addons($value) {
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map(function ($addon) {
        if (is_string($addon)) return ["name" => trim($addon), "price" => 0];
        return ["name" => trim((string) ($addon["name"] ?? "")), "price" => max(0, (float) ($addon["price"] ?? 0))];
    }, $decoded), fn($addon) =>$addon["name"] !== ""));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Self Ordering | Coffee Maker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:#f7f7f7;color:#000000}
.topbar{position:sticky;top:0;z-index:5;padding:13px 22px;background:linear-gradient(135deg,#5a2e1f,#7a4a2a);color:#fff;box-shadow:0 2px 15px #5a2e1f40}
.topbar-inner{max-width:1120px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand strong{display:block;font-size:16px;font-weight:700;letter-spacing:0.5px}
.brand small{color:#d4a574;font-size:11px}
.top-actions{display:flex;gap:8px}
.top-actions button{border:1px solid #ffffff40;border-radius:20px;padding:8px 14px;background:#ffffff1c;color:#fff;cursor:pointer;font-size:11px;font-family:inherit;font-weight:600}
.hero{max-width:1120px;margin:auto;padding:28px 22px 16px;text-align:center}
.hero h1{margin:0;color:#000000;font-size:26px;font-weight:700}
.hero p{margin:6px 0;color:#555555;font-size:13px}
.filters{max-width:1120px;margin:auto;padding:0 22px 18px}
.search{width:100%;height:40px;padding:0 14px;border:1px solid #e0e0e0;border-radius:20px;background:#fff;outline:none;font-family:inherit;color:#000000;font-size:12px}
.categories{display:flex;gap:8px;overflow-x:auto;padding-top:12px}
.category{border:0;border-radius:16px;padding:8px 14px;background:#ececee;color:#000000;white-space:nowrap;cursor:pointer;font-size:11px;font-family:inherit;font-weight:600}
.category.active,.category:hover{background:#74473b;color:#fff}
.menu{max-width:1120px;margin:auto;padding:0 22px 100px;display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:18px}
.card{overflow:hidden;border:1px solid #ededed;border-radius:7px;background:#fff;cursor:pointer;transition:transform .15s,box-shadow .15s}
.card:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(43,22,16,.1)}
.card-image{height:128px;display:grid;place-items:center;background:#74473b;color:#fff;font-size:34px;overflow:hidden}
.card-image img{width:100%;height:100%;object-fit:cover}
.card-body{padding:14px 13px;color:#000000;min-height:102px}
.card-body small{color:#000000;font-size:10px;font-weight:600;text-transform:uppercase;margin-bottom:10px;display:block}
.card-body h2{margin:0 0 9px;font-size:13px;line-height:1.35;color:#000000;font-weight:700}
.price{color:#000000;font-size:13px;font-weight:700}
.empty{text-align:center;grid-column:1/-1;color:#555555;padding:45px;font-size:13px}
.splash{position:fixed;inset:0;z-index:20;display:grid;place-items:center;padding:18px;background:#f7f7f7;cursor:pointer}
.splash.hidden{display:none}
.splash-card{width:min(100%,420px);padding:50px 25px;text-align:center;border:1px solid #ededed;border-radius:16px;background:#ffffff;box-shadow:0 8px 24px rgba(0,0,0,0.08)}
.splash-logo{width:100px;height:100px;object-fit:contain}
.splash-card h1{margin:20px 0 15px;font-size:28px;font-weight:700;color:#000000}
.splash-btn{width:100%;padding:16px 24px;border:none;border-radius:30px;background:#5a2e1f;color:#fff;font-size:15px;font-weight:700;letter-spacing:1px;cursor:pointer;box-shadow:0 4px 14px #5a2e1f59;transition:transform .2s,background-color .2s;font-family:inherit}
.splash-btn:hover,.splash-btn:active{background:#7a4a2a;transform:scale(1.02)}

/* Customizer Modal Styles (Matching POS) */
.modal-backdrop{position:fixed;inset:0;z-index:10;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(32,22,19,.68)}
.modal-backdrop.open{display:flex}
.customizer{width:min(100%,590px);max-height:90vh;overflow-y:auto;border-radius:12px;background:#fff;box-shadow:0 18px 45px rgba(0,0,0,.25);color:#000000}
.customizer-header{display:flex;align-items:center;gap:12px;padding:16px;border-bottom:1px solid #eee}
.customizer-title{flex:1}
.customizer-title strong{display:block;font-size:13px;color:#000000;font-weight:700}
.customizer-title small{color:#555555;font-size:10px}
.close-modal{border:0;border-radius:50%;width:27px;height:27px;background:#f0ecea;color:#000000;cursor:pointer;font-size:14px;display:grid;place-items:center}
.customizer-body{padding:17px;color:#000000}
.customizer-section{margin-bottom:17px}
.section-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;color:#000000;font-size:10px;font-weight:700;letter-spacing:.5px}
.section-label small{font-weight:400;color:#666666}
.option-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.option{position:relative;display:flex;align-items:center;gap:7px;min-height:38px;padding:8px;border:1px solid #e1d9d5;border-radius:6px;color:#000000;font-size:10px;cursor:pointer;user-select:none}
.option.selected{border-color:#000000;box-shadow:inset 0 0 0 1px #000000}
.option input{accent-color:#000000}
.option-price{margin-left:auto;color:#000000;font-size:9px;font-weight:700}
.notes{width:100%;min-height:48px;resize:vertical;border:1px solid #ded7d4;border-radius:6px;padding:10px;font-family:inherit;font-size:11px;color:#000000;outline:none}
.notes:focus{border-color:#74473b}
.customizer-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;background:#faf9f8;border-top:1px solid #f0ecea;border-radius:0 0 12px 12px}
.item-total small{display:block;color:#666666;font-size:9px;font-weight:700;letter-spacing:.5px}
.item-total strong{font-size:19px;color:#000000;font-weight:700}
.modal-actions{display:flex;align-items:center;gap:8px}
.quantity-control{display:flex;align-items:center;border:1px solid #ded7d4;border-radius:6px;overflow:hidden;background:#fff}
.quantity-control button{width:28px;height:32px;border:0;background:#fff;cursor:pointer;font-family:inherit;color:#000000;font-weight:700;font-size:14px}
.quantity-control button:hover{background:#f5f5f5}
.quantity-control span{width:25px;text-align:center;font-size:11px;font-weight:700;color:#000000}
.button{border:1px solid #ded5d1;border-radius:6px;background:#fff;color:#000000;padding:9px 14px;font-size:10px;font-family:inherit;cursor:pointer;font-weight:700}
.button.confirm{border-color:#241f1d;background:#241f1d;color:#fff}
.button.confirm:hover{background:#3a322e}

/* Cart Side/Overlay Modal */
.cart-modal{position:fixed;inset:0;z-index:10;display:none;place-items:center;padding:20px;background:rgba(32,22,19,.68)}
.cart-modal.open{display:grid}
.cart-card{width:min(100%,470px);max-height:90vh;overflow-y:auto;border-radius:12px;background:#fff;padding:22px;color:#000000}
.cart-card h2{margin:0 0 14px;font-size:18px;font-weight:700;color:#000000}
.cart-item-row{display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f0edeb;font-size:11px}
.cart-item-row strong{display:block;font-size:12px;color:#000000;font-weight:700}
.cart-item-row small{color:#666;font-size:10px}
.cart-item-price{font-weight:700;font-size:12px;white-space:nowrap}
.cart{position:fixed;right:20px;bottom:20px;z-index:6;border:0;border-radius:25px;padding:13px 20px;background:#7a4a2a;color:#fff;box-shadow:0 4px 16px #5a2e1f55;cursor:pointer;font-family:inherit;font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px}
.cart-count{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#fff;color:#7a4a2a;font-size:11px;font-weight:700}
@media (max-width:600px){
  .option-grid{grid-template-columns:1fr}
  .customizer-footer{flex-direction:column;align-items:stretch}
  .modal-actions{justify-content:flex-end}
}
</style>
</head>
<body>
<div class="splash" id="splash" onclick="startOrdering()">
    <div class="splash-card">
        <img class="splash-logo" src="../assets/images/logo.png" alt="Coffee Maker">
        <h1>COFFEE MAKER</h1>
        <button type="button" class="splash-btn">TAP TO CONTINUE</button>
    </div>
</div>

<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <strong>COFFEE MAKER</strong>
            <small>Self-Ordering</small>
        </div>
        <div class="top-actions">
            <button type="button" onclick="resetOrdering()">Start Over</button>
            <button type="button" onclick="showCart()">Cart <span id="topCount">0</span></button>
        </div>
    </div>
</header>

<section class="hero">
    <h1>Our Menu</h1>
    <p>Tap a product to customize and order</p>
</section>

<section class="filters">
    <input class="search" id="search" type="search" placeholder="Search menu items...">
    <div class="categories">
        <button class="category active" data-category="all">All</button>
        <?php foreach ($categories as$category): ?>
            <button class="category" data-category="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button>
        <?php endforeach; ?>
    </div>
</section>

<main class="menu" id="menu">
    <?php if (!$products): ?>
        <p class="empty">No active products found.</p>
    <?php endif; ?>
    <?php foreach ($products as$product): $addons = self_order_addons($product["addons"]); ?>
        <article class="card" 
                 data-name="<?= htmlspecialchars(strtolower($product["product_name"])) ?>" 
                 data-category="<?= htmlspecialchars($product["category"]) ?>" 
                 data-id="<?= (int) $product["id"] ?>" 
                 data-product="<?= htmlspecialchars($product["product_name"]) ?>" 
                 data-price="<?= htmlspecialchars($product["price"]) ?>" 
                 data-addons='<?= htmlspecialchars(json_encode($addons), ENT_QUOTES) ?>'>
            <div class="card-image">
                <?php if ($product["image_path"]): ?>
                    <img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>">
                <?php endif; ?>
            </div>
            <div class="card-body">
                <small><?= htmlspecialchars($product["category"]) ?></small>
                <h2><?= htmlspecialchars($product["product_name"]) ?></h2>
                <div class="price">₱<?= number_format((float) $product["price"], 2) ?></div>
            </div>
        </article>
    <?php endforeach; ?>
</main>

<button class="cart" type="button" onclick="showCart()">🛒 Cart <span class="cart-count" id="cartCount">0</span></button>

<!-- POS Style Product Customizer Modal -->
<div class="modal-backdrop" id="productModal" role="dialog" aria-modal="true" aria-labelledby="customizerName">
    <section class="customizer">
        <header class="customizer-header">
            <div class="customizer-title">
                <strong id="customizerName">Product Name</strong>
                <small id="customizerDescription">Base Price: ₱0.00</small>
            </div>
            <button class="close-modal" id="closeCustomizer" type="button" aria-label="Close" onclick="closeProduct()">×</button>
        </header>
        
        <div class="customizer-body">
            <!-- 1. SELECT SIZE -->
            <div class="customizer-section">
                <div class="section-label">
                    <span>1. SELECT SIZE <b>*</b></span>
                    <small>Required</small>
                </div>
                <div class="option-grid">
                    <label class="option selected">
                        <input type="radio" name="cupSize" value="Regular" data-price="0" checked>
                        <span>Regular</span>
                        <span class="option-price">₱0.00</span>
                    </label>
                    <label class="option">
                        <input type="radio" name="cupSize" value="Medium" data-price="25">
                        <span>Medium</span>
                        <span class="option-price">+₱25.00</span>
                    </label>
                    <label class="option">
                        <input type="radio" name="cupSize" value="Large" data-price="35">
                        <span>Large</span>
                        <span class="option-price">+₱35.00</span>
                    </label>
                </div>
            </div>

            <!-- ADD-ONS / TOPPINGS -->
            <div class="customizer-section" id="addonSection">
                <div class="section-label">
                    <span>ADD-ONS &amp; TOPPINGS</span>
                    <small id="addonHint">Pick options</small>
                </div>
                <div class="option-grid" id="addonOptions"></div>
            </div>

            <!-- SPECIAL INSTRUCTIONS / NOTES -->
            <div class="customizer-section">
                <div class="section-label">
                    <span>SPECIAL INSTRUCTIONS / NOTES</span>
                </div>
                <textarea class="notes" id="specialNotes" placeholder="e.g. Less ice, serve with separate straw..."></textarea>
            </div>
        </div>

        <footer class="customizer-footer">
            <div class="item-total">
                <small>ITEM TOTAL CALCULATION</small>
                <strong id="customizerTotal">₱0.00</strong>
            </div>
            <div class="modal-actions">
                <div class="quantity-control">
                    <button id="decreaseQuantity" type="button">−</button>
                    <span id="customizerQuantity">1</span>
                    <button id="increaseQuantity" type="button">+</button>
                </div>
                <button class="button" type="button" onclick="closeProduct()">CANCEL</button>
                <button class="button confirm" type="button" onclick="addToCart()">＋ ADD TO CART</button>
            </div>
        </footer>
    </section>
</div>

<!-- Cart Overview Modal -->
<div class="cart-modal" id="cartModal">
    <section class="cart-card">
        <h2>Your Cart</h2>
        <div id="cartItems"></div>
        <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center;">
            <strong>Total: <span id="cartTotal">₱0.00</span></strong>
        </div>
        <div class="modal-actions" style="margin-top: 18px; justify-content: flex-end;">
            <button class="button" type="button" onclick="closeCart()">Continue Ordering</button>
            <button class="button confirm" type="button" onclick="placeOrder()">Confirm & Place Order</button>
        </div>
    </section>
</div>

<script>
const cart = [];
let selectedProduct = null;
let itemQuantity = 1;

const money = value => `₱${Number(value || 0).toFixed(2)}`;
const escapeText = value => String(value || '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));

function startOrdering() { document.getElementById('splash').classList.add('hidden'); }
function resetOrdering() { cart.length = 0; updateCount(); document.getElementById('splash').classList.remove('hidden'); }
function closeProduct() { document.getElementById('productModal').classList.remove('open'); selectedProduct = null; }
function closeCart() { document.getElementById('cartModal').classList.remove('open'); }

function selectedSize() { return document.querySelector('input[name="cupSize"]:checked'); }

function updateCustomizerTotal() {
    const size = selectedSize();
    const sizePrice = size ? Number(size.dataset.price || 0) : 0;
    const addonTotal = [...document.querySelectorAll('#addonOptions input:checked')].reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
    const basePrice = Number(selectedProduct?.dataset.price || 0);
    const unitPrice = basePrice + sizePrice + addonTotal;
    
    document.getElementById('customizerTotal').textContent = money(unitPrice * itemQuantity);
    document.getElementById('customizerQuantity').textContent = itemQuantity;
}

document.querySelectorAll('.card').forEach(card => card.addEventListener('click', () => {
    selectedProduct = card;
    itemQuantity = 1;
    document.getElementById('customizerName').textContent = card.dataset.product;
    document.getElementById('customizerDescription').textContent = `Base Price: ${money(card.dataset.price)}`;
    document.getElementById('specialNotes').value = '';
    
    document.querySelectorAll('input[name="cupSize"]').forEach(input => {
        input.checked = input.value === 'Regular';
        input.closest('.option').classList.toggle('selected', input.checked);
    });

    let addons = [];
    try {
        addons = JSON.parse(card.dataset.addons || '[]');
    } catch (e) { addons = []; }

    addons = addons.map(a => typeof a === 'string' ? { name: a, price: 0 } : a);
    const addonOptions = document.getElementById('addonOptions');
    
    if (addons.length > 0) {
        addonOptions.innerHTML = addons.map(a => 
            `<label class="option">
                <input type="checkbox" value="${escapeText(a.name)}" data-name="${escapeText(a.name)}" data-price="${Number(a.price) || 0}">
                <span>${escapeText(a.name)}</span>
                <span class="option-price">+${money(a.price || 0)}</span>
            </label>`
        ).join('');
        document.getElementById('addonSection').hidden = false;
    } else {
        addonOptions.innerHTML = '';
        document.getElementById('addonSection').hidden = true;
    }

    document.getElementById('productModal').classList.add('open');
    updateCustomizerTotal();
}));

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

function addToCart() {
    if (!selectedProduct) return;
    const sizeInput = selectedSize();
    const sizeVal = sizeInput ? sizeInput.value : 'Regular';
    const sizePrice = sizeInput ? Number(sizeInput.dataset.price || 0) : 0;
    
    const selectedAddonInputs = [...document.querySelectorAll('#addonOptions input:checked')];
    const addons = selectedAddonInputs.map(input => ({
        name: input.dataset.name || input.value,
        price: Number(input.dataset.price || 0)
    }));
    const addonTotal = selectedAddonInputs.reduce((sum, input) => sum + Number(input.dataset.price || 0), 0);
    const basePrice = Number(selectedProduct.dataset.price || 0);
    const unitPrice = basePrice + sizePrice + addonTotal;
    const notes = document.getElementById('specialNotes').value.trim();

    cart.push({
        productId: Number(selectedProduct.dataset.id),
        name: selectedProduct.dataset.product,
        size: sizeVal,
        price: unitPrice,
        quantity: itemQuantity,
        addons: addons,
        notes: notes
    });

    updateCount();
    closeProduct();
}

function updateCount() {
    const totalCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    document.getElementById('cartCount').textContent = totalCount;
    document.getElementById('topCount').textContent = totalCount;
}

function showCart() {
    const container = document.getElementById('cartItems');
    let total = 0;
    if (!cart.length) {
        container.innerHTML = '<p class="empty">Your cart is empty.</p>';
    } else {
        container.innerHTML = cart.map((i, index) => {
            const subtotal = i.price * i.quantity;
            total += subtotal;
            const addonNames = i.addons.map(a => a.name).join(', ');
            return `<div class="cart-item-row">
                <div>
                    <strong>${i.quantity}x ${escapeText(i.name)} (${escapeText(i.size)})</strong>
                    <small>${addonNames ? 'Add-ons: ' + escapeText(addonNames) : 'No add-ons'}${i.notes ? '<br>Note: ' + escapeText(i.notes) : ''}</small>
                </div>
                <div class="cart-item-price">${money(subtotal)}</div>
            </div>`;
        }).join('');
    }
    document.getElementById('cartTotal').textContent = money(total);
    document.getElementById('cartModal').classList.open ? null : document.getElementById('cartModal').classList.add('open');
}

async function placeOrder() {
    if (!cart.length) { alert('Your cart is empty!'); return; }
    const items = cart.map(i => ({
        productId: i.productId,
        quantity: i.quantity,
        unitPrice: i.price,
        size: i.size || 'Regular',
        addons: i.addons.map(a => a.name),
        notes: i.notes
    }));
    const data = new FormData();
    data.append('action', 'create_order');
    data.append('order', JSON.stringify({ items }));

    try {
        const response = await fetch('pos.php?mode=self-order', { method: 'POST', body: data });
        const result = await response.json();
        if (result.success) {
            alert('Order placed successfully! Order #' + result.order_number);
            cart.length = 0;
            updateCount();
            closeCart();
        } else {
            alert(result.message || 'Unable to place order');
        }
    } catch (e) {
        alert('An error occurred while placing your order.');
    }
}

document.getElementById('search').addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.card').forEach(c => c.hidden = !c.dataset.name.includes(q));
});

document.querySelectorAll('.category').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.category').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    const cat = b.dataset.category;
    document.querySelectorAll('.card').forEach(c => c.hidden = cat !== 'all' && c.dataset.category !== cat);
}));

document.getElementById('productModal').addEventListener('click', event => {
    if (event.target === document.getElementById('productModal')) closeProduct();
});
</script>
</body>
</html>