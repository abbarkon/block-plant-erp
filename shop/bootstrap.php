<?php
/**
 * Public online shop bootstrap.
 *
 * The shop is deliberately separate from the staff login: customers either
 * check out as guests or create a shop account that is linked to a normal
 * `customers` record, so every online order is visible in the ERP.
 *
 * All tables are created on demand (same pattern as ensure_gate_schema) so the
 * feature can be deployed by uploading files only - no manual SQL required.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!defined('SHOP_PREFIX')) {
    define('SHOP_PREFIX', '/block_plant/shop');
}

function ensure_shop_schema() {
    global $pdo;
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        email VARCHAR(100) NOT NULL,
        phone VARCHAR(50) NULL,
        password_hash VARCHAR(255) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_shop_account_email (email),
        KEY idx_shop_account_customer (customer_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS online_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(40) NOT NULL,
        customer_id INT NULL,
        account_id INT NULL,
        customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        email VARCHAR(100) NULL,
        address VARCHAR(255) NULL,
        delivery_date DATE NULL,
        notes TEXT NULL,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        staff_notes VARCHAR(255) NULL,
        sale_id INT NULL,
        confirmed_by INT NULL,
        confirmed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_online_order_no (order_no),
        KEY idx_online_order_status (status),
        KEY idx_online_order_customer (customer_id),
        KEY idx_online_order_sale (sale_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS online_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        block_type_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        total_price DECIMAL(14,2) NOT NULL,
        KEY idx_online_order_items_order (order_id),
        KEY idx_online_order_items_block (block_type_id)
    )");
    $done = true;
}

/**
 * Bilingual shop strings. Staff pages reuse this helper so the new screens are
 * fully translated without touching the main lang.php dictionary.
 */
function shop_t($en) {
    static $am = [
        'Online Shop' => 'የመስመር ላይ መሸጫ',
        'Shop' => 'መሸጫ',
        'Home' => 'መነሻ',
        'Order blocks online' => 'ብሎኮችን በመስመር ላይ ይዘዙ',
        'Browse our block range, add what you need to the cart, and place your order. Sign in for faster checkout or continue as a guest.' => 'የብሎክ ዓይነቶቻችንን ይመልከቱ፣ የሚፈልጉትን ወደ ጋሪ ያክሉ እና ትዕዛዝዎን ያስገቡ። ለፈጣን ግዢ ይግቡ ወይም እንደ እንግዳ ይቀጥሉ።',
        'Products' => 'ምርቶች',
        'Price per block' => 'ዋጋ በአንድ ብሎክ',
        'In stock: %s' => 'በክምችት፦ %s',
        'Curing: %s' => 'በማድረቅ ላይ፦ %s',
        'Curing' => 'በማድረቅ ላይ',
        'Ready to sell' => 'ለሽያጭ ዝግጁ',
        'Out of stock — made to order' => 'ከክምችት ውጪ — በትዕዛዝ ይመረታል',
        'Add to cart' => 'ወደ ጋሪ አክል',
        'Quantity' => 'ብዛት',
        'Cart' => 'ጋሪ',
        'Your cart is empty.' => 'ጋሪዎ ባዶ ነው።',
        'Browse products' => 'ምርቶችን ይመልከቱ',
        'Continue shopping' => 'ግዢን ይቀጥሉ',
        'Checkout' => 'ትዕዛዝ ያስገቡ',
        'Subtotal' => 'ንዑስ ድምር',
        'Total' => 'ጠቅላላ',
        'Delivery is arranged after we confirm your order.' => 'ትዕዛዝዎን ካረጋገጥን በኋላ ማድረስ ይዘጋጃል።',
        'items' => 'ዕቃዎች',
        'item' => 'ዕቃ',
        'How it works' => 'እንዴት ይሰራል',
        'Choose your blocks' => 'ብሎኮችዎን ይምረጡ',
        'Place your order' => 'ትዕዛዝዎን ያስገቡ',
        'We confirm and deliver' => 'እኛ አረጋግጠን እናደርሳለን',
        'Pick the sizes you need and add them to your cart.' => 'የሚፈልጉትን መጠኖች መርጠው ወደ ጋሪ ያክሉ።',
        'Check out as a guest or sign in to your account.' => 'እንደ እንግዳ ይግዙ ወይም ወደ መለያዎ ይግቡ።',
        'Our team calls you to confirm availability, payment and delivery.' => 'ቡድናችን አቅርቦቱን፣ ክፍያውን እና ማድረሱን ለማረጋገጥ ይደውልልዎታል።',
        'Your cart' => 'ጋሪዎ',
        'Product' => 'ምርት',
        'Unit price' => 'የአንድ ክፍል ዋጋ',
        'Line total' => 'የመስመር ድምር',
        'Update cart' => 'ጋሪ አዘምን',
        'Remove' => 'አስወግድ',
        'Clear cart' => 'ጋሪ አጽዳ',
        'Proceed to checkout' => 'ወደ ትዕዛዝ ይቀጥሉ',
        'Order summary' => 'የትዕዛዝ ማጠቃለያ',
        'Final price is confirmed by our team.' => 'የመጨረሻው ዋጋ በቡድናችን ይረጋገጣል።',
        'Contact details' => 'የመገኛ ዝርዝሮች',
        'Full name' => 'ሙሉ ስም',
        'Phone number' => 'የስልክ ቁጥር',
        'Email' => 'ኢሜይል',
        'Email (optional)' => 'ኢሜይል (አማራጭ)',
        'Delivery address' => 'የማድረሻ አድራሻ',
        'Preferred delivery date' => 'የሚፈልጉት የማድረሻ ቀን',
        'Order notes' => 'የትዕዛዝ ማስታወሻ',
        'Payment method' => 'የክፍያ ዘዴ',
        'Cash on delivery' => 'በማድረሻ ጊዜ ጥሬ ገንዘብ',
        'Bank transfer' => 'የባንክ ዝውውር',
        'Telebirr' => 'ቴሌብር',
        'Place order' => 'ትዕዛዝ አስገባ',
        'Ordering as' => 'በመግዛት ላይ',
        'Sign in' => 'ግባ',
        'Not you?' => 'እርስዎ አይደሉም?',
        'Create an account for faster checkout' => 'ለፈጣን ግዢ መለያ ይፍጠሩ',
        'Password' => 'የይለፍ ቃል',
        'Confirm password' => 'የይለፍ ቃል ያረጋግጡ',
        'Passwords do not match.' => 'የይለፍ ቃላቱ አይመሳሰሉም።',
        'Password must be at least 6 characters.' => 'የይለፍ ቃል ቢያንስ 6 ፊደላት መሆን አለበት።',
        'An account with this email already exists. Please sign in.' => 'በዚህ ኢሜይል መለያ አስቀድሞ አለ። እባክዎ ይግቡ።',
        'Email is required to create an account.' => 'መለያ ለመፍጠር ኢሜይል ያስፈልጋል።',
        'Please fix the following:' => 'የሚከተሉትን ያስተካክሉ፦',
        'Name is required.' => 'ስም ያስፈልጋል።',
        'Phone number is required.' => 'የስልክ ቁጥር ያስፈልጋል።',
        'Enter a valid email address.' => 'ትክክለኛ ኢሜይል ያስገቡ።',
        'Enter a valid delivery date.' => 'ትክክለኛ የማድረሻ ቀን ያስገቡ።',
        'Some products in your cart are no longer available. Please review your cart.' => 'በጋሪዎ ውስጥ ያሉ አንዳንድ ምርቶች ከእንግዲህ አይገኙም። እባክዎ ጋሪዎን ይመልከቱ።',
        'Back to cart' => 'ወደ ጋሪ ተመለስ',
        'Thank you! Your order has been received.' => 'እናመሰግናለን! ትዕዛዝዎ ደርሶናል።',
        'Order number' => 'የትዕዛዝ ቁጥር',
        'We will call you to confirm availability, payment and delivery.' => 'አቅርቦቱን፣ ክፍያውን እና ማድረሱን ለማረጋገጥ እንደውላለን።',
        'Track this order' => 'ይህን ትዕዛዝ ይከታተሉ',
        'A confirmation has been saved for our sales team.' => 'ማረጋገጫው ለሽያጭ ቡድናችን ተቀምጧል።',
        'Track your order' => 'ትዕዛዝዎን ይከታተሉ',
        'Enter the order number and the phone number used at checkout.' => 'የትዕዛዝ ቁጥሩን እና በትዕዛዝ ጊዜ የተጠቀሙበትን ስልክ ቁጥር ያስገቡ።',
        'Track order' => 'ትዕዛዝ ይከታተሉ',
        'Order not found. Check the order number and phone number.' => 'ትዕዛዝ አልተገኘም። የትዕዛዝ ቁጥሩን እና ስልክ ቁጥሩን ያረጋግጡ።',
        'Order status' => 'የትዕዛዝ ሁኔታ',
        'Placed on' => 'የተደረገበት ቀን',
        'Delivery date' => 'የማድረሻ ቀን',
        'Items' => 'ዕቃዎች',
        'Notes' => 'ማስታወሻዎች',
        'Need help? Call us at' => 'እርዳታ ይፈልጋሉ? ይደውሉልን',
        'Create your account' => 'መለያዎን ይፍጠሩ',
        'Sign up to check out faster and follow your orders.' => 'ለፈጣን ግዢ እና ትዕዛዞችዎን ለመከታተል ይመዝገቡ።',
        'Already have an account?' => 'አስቀድመው መለያ አለዎት?',
        'Create account' => 'መለያ ይፍጠሩ',
        'Account created. Welcome!' => 'መለያ ተፈጥሯል። እንኳን ደህና መጡ!',
        'Welcome back' => 'እንኳን ደህና መጡ',
        'Sign in to your account to check out faster and follow your orders.' => 'ለፈጣን ግዢ እና ትዕዛዞችዎን ለመከታተል ወደ መለያዎ ይግቡ።',
        'Email or phone' => 'ኢሜይል ወይም ስልክ',
        'New here?' => 'አዲስ ነዎት?',
        'Invalid email/phone or password.' => 'ኢሜይል/ስልክ ወይም የይለፍ ቃል ትክክል አይደለም።',
        'Too many attempts. Please try again in a few minutes.' => 'ብዙ ሙከራዎች። እባክዎ ከጥቂት ደቂቃዎች በኋላ ይሞክሩ።',
        'My account' => 'መለያዬ',
        'My orders' => 'ትዕዛዞቼ',
        'Profile' => 'መገለጫ',
        'Name' => 'ስም',
        'Phone' => 'ስልክ',
        'Address' => 'አድራሻ',
        'Log out' => 'ውጣ',
        'No orders yet.' => 'እስካሁን ትዕዛዝ የለም።',
        'Start shopping' => 'ግዢ ይጀምሩ',
        'View order' => 'ትዕዛዝ ይመልከቱ',
        'Order' => 'ትዕዛዝ',
        'Status' => 'ሁኔታ',
        'Date' => 'ቀን',
        'Order details' => 'የትዕዛዝ ዝርዝር',
        'Back to my orders' => 'ወደ ትዕዛዞቼ ተመለስ',
        'Pending' => 'በመጠባበቅ ላይ',
        'Confirmed' => 'ተረጋግጧል',
        'Ready' => 'ዝግጁ',
        'Completed' => 'ተጠናቋል',
        'Cancelled' => 'ተሰርዟል',
        'Online Orders' => 'የመስመር ላይ ትዕዛዞች',
        'Orders placed on the public shop.' => 'በሕዝብ መሸጫ ላይ የተደረጉ ትዕዛዞች።',
        'Open shop' => 'መሸጫውን ክፈት',
        'All' => 'ሁሉም',
        'Customer' => 'ደንበኛ',
        'Placed' => 'የተደረገበት',
        'Actions' => 'እርምጃዎች',
        'View' => 'ይመልከቱ',
        'No online orders yet.' => 'እስካሁን የመስመር ላይ ትዕዛዝ የለም።',
        'Search by order number, customer or phone' => 'በትዕዛዝ ቁጥር፣ በደንበኛ ወይም በስልክ ይፈልጉ',
        'Filter' => 'አጣራ',
        'Reset' => 'ዳግም አስጀምር',
        'New' => 'አዲስ',
        'This order was placed on the public shop. Confirm it, then create an invoice when the customer collects or is dispatched.' => 'ይህ ትዕዛዝ በሕዝብ መሸጫ ላይ ተደርጓል። ያረጋግጡት፣ ከዚያ ደንበኛው ሲወስድ ወይም ሲላክ ደረሰኝ ይፍጠሩ።',
        'Back to online orders' => 'ወደ የመስመር ላይ ትዕዛዞች ተመለስ',
        'Customer details' => 'የደንበኛ ዝርዝር',
        'Delivery' => 'ማድረስ',
        'Staff notes' => 'የሰራተኛ ማስታወሻ',
        'Confirm order' => 'ትዕዛዝ አረጋግጥ',
        'Mark as ready' => 'ዝግጁ በል',
        'Cancel order' => 'ትዕዛዝ ሰርዝ',
        'Create invoice' => 'ደረሰኝ ፍጠር',
        'View invoice' => 'ደረሰኝ ይመልከቱ',
        'Current stock' => 'የአሁኑ ክምችት',
        'Stock is sufficient for this order.' => 'ክምችቱ ለዚህ ትዕዛዝ በቂ ነው።',
        'Stock is short for one or more items. Confirm production before dispatch.' => 'ለአንድ ወይም ከዚያ በላይ ዕቃዎች ክምችት አይበቃም። ከመላክ በፊት ምርቱን ያረጋግጡ።',
        'Order timeline' => 'የትዕዛዝ የጊዜ መስመር',
        'Confirmed by' => 'ያረጋገጠው',
        'Cancelled on' => 'የተሰረዘበት',
        'Save notes' => 'ማስታወሻ አስቀምጥ',
        'Notes saved.' => 'ማስታወሻ ተቀምጧል።',
        'Order confirmed.' => 'ትዕዛዙ ተረጋግጧል።',
        'Order marked ready.' => 'ትዕዛዙ ዝግጁ ሆኗል።',
        'Order cancelled.' => 'ትዕዛዙ ተሰርዟል።',
        'This order is already completed or cancelled.' => 'ይህ ትዕዛዝ አስቀድሞ ተጠናቋል ወይም ተሰርዟል።',
        'Cancel this online order?' => 'ይህን የመስመር ላይ ትዕዛዝ ይሰርዙ?',
        'Reason for cancellation' => 'የመሰረዝ ምክንያት',
        'Order status updated.' => 'የትዕዛዝ ሁኔታ ተዘምኗል።',
        'Qty' => 'ብዛት',
        'Available' => 'ይገኛል',
        'Short by' => 'የጎደለው',
        'Order total' => 'የትዕዛዝ ጠቅላላ',
        'Customer account' => 'የደንበኛ መለያ',
        'Guest checkout' => 'እንደ እንግዳ የተደረገ',
        'Registered customer' => 'የተመዘገበ ደንበኛ',
        'Create invoice to fulfil this order. The invoice will deduct stock and create the gate clearance.' => 'ይህን ትዕዛዝ ለማሟላት ደረሰኝ ይፍጠሩ። ደረሰኙ ክምችት ይቀንሳል እና የበር መፍቀድ ይፈጥራል።',
        'Order cancelled by staff.' => 'ትዕዛዙ በሰራተኛ ተሰርዟል።',
        'Account' => 'መለያ',
        'Track' => 'ክትትል',
        'Search' => 'ፈልግ',
        'Close' => 'ዝጋ',
        'Company' => 'ኩባንያ',
        'Contact' => 'አግኙን',
        'All rights reserved.' => 'መብቱ በህግ የተጠበቀ ነው።',
        'Order placed successfully.' => 'ትዕዛዙ በተሳካ ሁኔታ ተደርጓል።',
        'Your order could not be placed. Please try again.' => 'ትዕዛዝዎን ማስገባት አልተቻለም። እባክዎ እንደገና ይሞክሩ።',
        '%s added to your cart.' => '%s ወደ ጋሪዎ ተጨምሯል።',
        'Address must be 255 characters or fewer.' => 'አድራሻው 255 ፊደላት ወይም ከዚያ ያነሰ መሆን አለበት።',
        'An account already exists for this phone or email. Please sign in.' => 'በዚህ ስልክ ወይም ኢሜይል መለያ አስቀድሞ አለ። እባክዎ ይግቡ።',
        'Anything we should know? (optional)' => 'ማወቅ ያለብን ነገር አለ? (አማራጭ)',
        'Area, street, landmark' => 'አካባቢ፣ መንገድ፣ መለያ ስፍራ',
        'Arranged after confirmation' => 'ከማረጋገጫ በኋላ ይዘጋጃል',
        'Browse our blocks and add what you need.' => 'ብሎኮቻችንን ይመልከቱ እና የሚፈልጉትን ያክሉ።',
        'Cart cleared.' => 'ጋሪው ጸድቷል።',
        'Cart updated.' => 'ጋሪው ተዘምኗል።',
        'Create an account' => 'መለያ ይፍጠሩ',
        'Customer code' => 'የደንበኛ ኮድ',
        'Internal notes for the sales team' => 'ለሽያጭ ቡድኑ የውስጥ ማስታወሻ',
        'Item removed from your cart.' => 'ዕቃው ከጋሪዎ ተወግዷል።',
        'Language' => 'ቋንቋ',
        'Need to change your details? Contact us and we will update your profile.' => 'ዝርዝሮችዎን መቀየር ይፈልጋሉ? ያግኙን እና መገለጫዎን እናዘምናለን።',
        'No payment is taken online. We confirm your order first.' => 'በመስመር ላይ ክፍያ አይወሰድም። መጀመሪያ ትዕዛዝዎን እናረጋግጣለን።',
        'No products are available right now.' => 'በአሁኑ ጊዜ ምርቶች አይገኙም።',
        'Online order' => 'የመስመር ላይ ትዕዛዝ',
        'Optional' => 'አማራጭ',
        'Or' => 'ወይም',
        'Or continue as a guest below.' => 'ወይም ከታች እንደ እንግዳ ይቀጥሉ።',
        'Order notes must be 1,000 characters or fewer.' => 'የትዕዛዝ ማስታወሻ 1,000 ፊደላት ወይም ከዚያ ያነሰ መሆን አለበት።',
        'Please check back soon or contact us directly.' => 'እባክዎ በቅርቡ ይመለሱ ወይም በቀጥታ ያግኙን።',
        'Remove all items from your cart?' => 'ሁሉንም ዕቃዎች ከጋሪዎ ያስወግዱ?',
        'Save your details and follow your orders. Optional.' => 'ዝርዝሮችዎን ያስቀምጡ እና ትዕዛዞችዎን ይከታተሉ። አማራጭ።',
        'That product is no longer available.' => 'ያ ምርት ከእንግዲህ አይገኝም።',
        'The items and prices are pre-filled. Add the vehicle plate and payment details to issue the invoice.' => 'ዕቃዎቹና ዋጋዎቹ አስቀድመው ተሞልተዋል። ደረሰኙን ለማውጣት የተሽከርካሪ ቁጥሩን እና የክፍያ ዝርዝሩን ያክሉ።',
        'We could not create your account. Please try again.' => 'መለያዎን መፍጠር አልቻልንም። እባክዎ እንደገና ይሞክሩ።',
        'We use these details to confirm and deliver your order.' => 'እነዚህን ዝርዝሮች ትዕዛዝዎን ለማረጋገጥና ለማድረስ እንጠቀምባቸዋለን።',
        'You have been logged out.' => 'ወጥተዋል።',
        'Your email is used to sign in.' => 'ኢሜይልዎ ለመግቢያ ያገለግላል።',
        'Your session expired. Please refresh the page and try again.' => 'ክፍለ ጊዜዎ አልቋል። እባክዎ ገጹን ያድሱ እና እንደገና ይሞክሩ።',
        'block' => 'ብሎክ',
        'for' => 'ለ',
        'to see all your orders.' => 'ሁሉንም ትዕዛዞችዎን ለማየት።',
    ];
    return app_lang() === 'am' ? ($am[$en] ?? $en) : $en;
}

function shop_money($amount) {
    return currency_symbol() . number_format((float) $amount, 2);
}

function shop_url($file = '') {
    return SHOP_PREFIX . '/' . ltrim((string) $file, '/');
}

function shop_redirect($file) {
    header('Location: ' . (strpos($file, 'http') === 0 || strpos($file, '/') === 0 ? $file : shop_url($file)));
    exit;
}

function shop_flash($type, $message) {
    $_SESSION['shop_flash'] = ['type' => $type, 'message' => $message];
}

function shop_get_flash() {
    if (empty($_SESSION['shop_flash'])) {
        return null;
    }
    $flash = $_SESSION['shop_flash'];
    unset($_SESSION['shop_flash']);
    return $flash;
}

function shop_csrf_token() {
    if (empty($_SESSION['shop_csrf'])) {
        $_SESSION['shop_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['shop_csrf'];
}

function shop_csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(shop_csrf_token()) . '">';
}

function shop_check_csrf() {
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || empty($_SESSION['shop_csrf']) || !hash_equals($_SESSION['shop_csrf'], $sent)) {
        http_response_code(419);
        exit(shop_t('Your session expired. Please refresh the page and try again.'));
    }
}

function shop_phone_key($phone) {
    return preg_replace('/[^0-9]/', '', (string) $phone);
}

function shop_cart() {
    $cart = $_SESSION['shop_cart'] ?? [];
    if (!is_array($cart)) {
        return [];
    }
    $clean = [];
    foreach ($cart as $id => $qty) {
        $id = (int) $id;
        $qty = (int) $qty;
        if ($id > 0 && $qty > 0) {
            $clean[$id] = min($qty, 999999);
        }
    }
    return $clean;
}

function shop_cart_set(array $cart) {
    $_SESSION['shop_cart'] = $cart;
}

function shop_cart_count() {
    return array_sum(shop_cart());
}

/**
 * Cart lines joined with live product data. Prices always come from the
 * database, never from the browser.
 */
function shop_cart_items() {
    global $pdo;
    ensure_production_curing_schema();
    $cart = shop_cart();
    if (!$cart) {
        return [];
    }
    $ids = array_keys($cart);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT b.id, b.name, b.size, b.selling_price, COALESCE(f.quantity, 0) AS stock
        FROM block_types b
        LEFT JOIN finished_stock f ON f.block_type_id = b.id
        WHERE b.status = 1 AND b.selling_price > 0 AND b.id IN ($in)
        ORDER BY b.name, b.size");
    $stmt->execute($ids);
    $items = [];
    $found = [];
    foreach ($stmt->fetchAll() as $row) {
        $qty = $cart[(int) $row['id']] ?? 0;
        if ($qty <= 0) {
            continue;
        }
        $found[] = (int) $row['id'];
        $curing = finished_curing_quantity((int) $row['id']);
        $row['total_stock'] = (int) $row['stock'];
        $row['curing'] = $curing;
        $row['stock'] = max(0, (int) $row['stock'] - $curing);
        $row['quantity'] = $qty;
        $row['line_total'] = round($qty * (float) $row['selling_price'], 2);
        $items[] = $row;
    }
    // Drop products that were removed or deactivated.
    if (count($found) !== count($cart)) {
        shop_cart_set(array_intersect_key($cart, array_flip($found)));
    }
    return $items;
}

function shop_cart_totals(array $items) {
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item['line_total'];
    }
    $subtotal = round($subtotal, 2);
    return ['subtotal' => $subtotal, 'total' => $subtotal];
}

function shop_products() {
    global $pdo;
    ensure_production_curing_schema();
    $products = $pdo->query("SELECT b.id, b.name, b.size, b.selling_price, COALESCE(f.quantity, 0) AS stock
        FROM block_types b
        LEFT JOIN finished_stock f ON f.block_type_id = b.id
        WHERE b.status = 1 AND b.selling_price > 0
        ORDER BY b.name, b.size")->fetchAll();
    foreach ($products as &$product) {
        $curing = finished_curing_quantity((int) $product['id']);
        $product['total_stock'] = (int) $product['stock'];
        $product['curing'] = $curing;
        $product['stock'] = max(0, (int) $product['stock'] - $curing);
    }
    unset($product);
    return $products;
}

function shop_current_account() {
    global $pdo;
    $accountId = (int) ($_SESSION['shop_account_id'] ?? 0);
    if ($accountId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT sa.*, c.name, c.phone AS customer_phone, c.email AS customer_email,
            c.address AS customer_address, c.customer_code
        FROM shop_accounts sa
        JOIN customers c ON c.id = sa.customer_id
        WHERE sa.id = ? AND sa.status = 1");
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['shop_account_id'], $_SESSION['shop_customer_id']);
        return null;
    }
    return $row;
}

function shop_login_account($identifier, $password) {
    global $pdo;
    $identifier = trim((string) $identifier);
    if ($identifier === '' || $password === '') {
        return null;
    }
    $phoneKey = shop_phone_key($identifier);
    $phoneLike = (filter_var($identifier, FILTER_VALIDATE_EMAIL) === false && strlen($phoneKey) >= 9)
        ? '%' . substr($phoneKey, -9)
        : null;
    $sql = "SELECT sa.*, c.name, c.phone AS customer_phone, c.email AS customer_email,
            c.address AS customer_address, c.customer_code
        FROM shop_accounts sa
        JOIN customers c ON c.id = sa.customer_id
        WHERE sa.status = 1 AND (sa.email = ? OR sa.phone = ? OR c.phone = ?";
    $params = [$identifier, $identifier, $identifier];
    if ($phoneLike !== null) {
        $sql .= " OR sa.phone LIKE ? OR c.phone LIKE ?";
        $params[] = $phoneLike;
        $params[] = $phoneLike;
    }
    $sql .= ") LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $account = $stmt->fetch();
    if ($account && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['shop_account_id'] = (int) $account['id'];
        $_SESSION['shop_customer_id'] = (int) $account['customer_id'];
        $pdo->prepare("UPDATE shop_accounts SET last_login_at = NOW() WHERE id = ?")->execute([(int) $account['id']]);
        return $account;
    }
    return null;
}

function shop_generate_customer_code() {
    global $pdo;
    for ($i = 0; $i < 8; $i++) {
        $candidate = 'WEB-' . date('YmdHis') . random_int(10, 99);
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_code = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
        usleep(200000);
    }
    return 'WEB-' . date('YmdHis') . random_int(1000, 9999);
}

/**
 * Find an existing customer by phone or email, or create one so the sales team
 * has a proper customer record to invoice.
 */
function shop_find_or_create_customer($name, $phone, $email, $address = '') {
    global $pdo;
    $name = trim((string) $name);
    $phone = trim((string) $phone);
    $email = trim((string) $email);
    $address = trim((string) $address);

    $phoneKey = shop_phone_key($phone);
    $customer = null;
    if ($phoneKey !== '') {
        // Match regardless of spaces, dashes or country-code formatting.
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $customer = $stmt->fetch() ?: null;
        if (!$customer && strlen($phoneKey) >= 9) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone LIKE ? ORDER BY id LIMIT 1");
            $stmt->execute(['%' . substr($phoneKey, -9)]);
            $customer = $stmt->fetch() ?: null;
        }
    }
    if (!$customer && $email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $customer = $stmt->fetch() ?: null;
    }
    if ($customer) {
        // Fill gaps without overwriting details the office already maintains.
        $updates = [];
        $params = [];
        if (empty($customer['phone']) && $phone !== '') { $updates[] = 'phone = ?'; $params[] = $phone; }
        if (empty($customer['email']) && $email !== '') { $updates[] = 'email = ?'; $params[] = $email; }
        if (empty($customer['address']) && $address !== '') { $updates[] = 'address = ?'; $params[] = $address; }
        if ($updates) {
            $params[] = (int) $customer['id'];
            $pdo->prepare("UPDATE customers SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        return (int) $customer['id'];
    }

    $code = shop_generate_customer_code();
    $stmt = $pdo->prepare("INSERT INTO customers(customer_code, name, phone, email, address, credit_limit, opening_balance, status)
        VALUES(?,?,?,?,?,0,0,1)");
    $stmt->execute([
        $code,
        $name,
        $phone !== '' ? $phone : null,
        $email !== '' ? $email : null,
        $address !== '' ? $address : null,
    ]);
    return (int) $pdo->lastInsertId();
}

function shop_create_account($customerId, $email, $phone, $password) {
    global $pdo;
    $email = trim((string) $email);
    $phone = trim((string) $phone);
    $stmt = $pdo->prepare("SELECT id FROM shop_accounts WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM shop_accounts WHERE customer_id = ? LIMIT 1");
    $stmt->execute([(int) $customerId]);
    if ($stmt->fetchColumn()) {
        return null;
    }
    $stmt = $pdo->prepare("INSERT INTO shop_accounts(customer_id, email, phone, password_hash, status) VALUES(?,?,?,?,1)");
    $stmt->execute([(int) $customerId, $email, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT)]);
    $accountId = (int) $pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['shop_account_id'] = $accountId;
    $_SESSION['shop_customer_id'] = (int) $customerId;
    return $accountId;
}

function shop_generate_order_no() {
    global $pdo;
    for ($i = 0; $i < 8; $i++) {
        $candidate = 'WEB-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare("SELECT id FROM online_orders WHERE order_no = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }
    return 'WEB-' . date('ymdHis') . '-' . random_int(100, 999);
}

function shop_status_label($status) {
    $labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'ready' => 'Ready',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    return shop_t($labels[$status] ?? ucfirst((string) $status));
}

function shop_status_class($status) {
    return in_array($status, ['pending', 'confirmed', 'ready', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function shop_order($orderNo) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM online_orders WHERE order_no = ? LIMIT 1");
    $stmt->execute([trim((string) $orderNo)]);
    return $stmt->fetch() ?: null;
}

function shop_order_items($orderId) {
    global $pdo;
    ensure_production_curing_schema();
    $stmt = $pdo->prepare("SELECT oi.*, b.name, b.size, COALESCE(f.quantity, 0) AS stock
        FROM online_order_items oi
        LEFT JOIN block_types b ON b.id = oi.block_type_id
        LEFT JOIN finished_stock f ON f.block_type_id = oi.block_type_id
        WHERE oi.order_id = ?
        ORDER BY oi.id");
    $stmt->execute([(int) $orderId]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $curing = finished_curing_quantity((int) $item['block_type_id']);
        $item['total_stock'] = (int) $item['stock'];
        $item['curing'] = $curing;
        $item['stock'] = max(0, (int) $item['stock'] - $curing);
    }
    unset($item);
    return $items;
}
