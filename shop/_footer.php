<?php
$shopFootCompany = company_settings();
?>
</main>
<footer class="shop-foot">
    <div class="wrap shop-foot-inner">
        <div>
            <strong>🧱 <?= e(company_display_name()) ?></strong>
            <?php if (!empty($shopFootCompany['address'])): ?><div><?= e($shopFootCompany['address']) ?></div><?php endif; ?>
            <div>
                <?php if (!empty($shopFootCompany['phone'])): ?><a href="tel:<?= e($shopFootCompany['phone']) ?>"><?= e($shopFootCompany['phone']) ?></a><?php endif; ?>
                <?php if (!empty($shopFootCompany['phone']) && !empty($shopFootCompany['email'])): ?> · <?php endif; ?>
                <?php if (!empty($shopFootCompany['email'])): ?><a href="mailto:<?= e($shopFootCompany['email']) ?>"><?= e($shopFootCompany['email']) ?></a><?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div><a href="<?= e(shop_url('index.php')) ?>"><?= e(shop_t('Shop')) ?></a> · <a href="<?= e(shop_url('track.php')) ?>"><?= e(shop_t('Track order')) ?></a></div>
            <div style="margin-top:6px;">© <?= date('Y') ?> <?= e(company_display_name()) ?>. <?= e(shop_t('All rights reserved.')) ?></div>
        </div>
    </div>
</footer>
</body>
</html>
