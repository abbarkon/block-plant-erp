<?php
// Yield: how many blocks one cement bag makes, per block type (Settings > Block Types).
// When a yield is set it is used by the cement calculators and the efficiency report; otherwise the BOM recipe is used.

require_once __DIR__ . '/labor_settings.php';

function block_yield_ensure_schema() {
    static $done = false;
    if ($done) return;
    global $pdo;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM block_types")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('yield_per_bag', $cols, true)) {
            $pdo->exec("ALTER TABLE block_types ADD COLUMN yield_per_bag DECIMAL(10,2) NULL");
            $cols[] = 'yield_per_bag';
        }
        // Pick up values saved earlier under another column name (for example "yield" or "blocks_per_bag").
        foreach ($cols as $c) {
            if ($c !== 'yield_per_bag' && preg_match('/yield|per_bag|per_cement|blocks_per/i', $c)) {
                try {
                    $pdo->exec("UPDATE block_types SET yield_per_bag = `{$c}` WHERE yield_per_bag IS NULL AND `{$c}` IS NOT NULL AND `{$c}` > 0");
                } catch (Throwable $e) {
                }
            }
        }
    } catch (Throwable $e) {
    }
    $done = true;
}

/** [blockTypeId => blocks per cement bag] for block types that have a yield. */
function block_yield_map() {
    global $pdo;
    static $map = null;
    if ($map !== null) return $map;
    block_yield_ensure_schema();
    $map = [];
    try {
        foreach ($pdo->query("SELECT id, yield_per_bag FROM block_types WHERE yield_per_bag IS NOT NULL AND yield_per_bag > 0")->fetchAll() as $r) {
            $map[(int) $r['id']] = (float) $r['yield_per_bag'];
        }
    } catch (Throwable $e) {
    }
    return $map;
}

/** Cement needed per block, in the cement material's own unit (kg or bags), from the yield. */
function block_yield_cement_per_block($blockTypeId, $cementUnit, $kgPerBag = 50) {
    $y = block_yield_map()[(int) $blockTypeId] ?? 0;
    if ($y <= 0) return 0;
    $perBag = preg_match('/\bkg\b|kilo/i', (string) $cementUnit) ? (float) $kgPerBag : 1.0;
    return $perBag / $y;
}

/**
 * When a yield is saved on a block type, auto-update the block_bom cement line
 * so quantity_per_block = cement per block derived from the yield.
 *
 * yield_per_bag = blocks per one cement bag.
 * If cement unit is kg:   quantity_per_block = kg_per_bag / yield_per_bag
 * If cement unit is bags: quantity_per_block = 1 / yield_per_bag
 */
function block_yield_sync_to_bom($blockTypeId, $yieldPerBag) {
    global $pdo;
    $yieldPerBag = (float) $yieldPerBag;
    if ($yieldPerBag <= 0) {
        return false;
    }

    $cement = labor_cement_material();
    if (!$cement) {
        return false;
    }

    $kgPerBag = labor_kg_per_bag();
    $cementUnit = (string) ($cement['unit'] ?? '');

    if (preg_match('/\bkg\b|kilo/i', $cementUnit)) {
        $quantityPerBlock = $kgPerBag / $yieldPerBag;
    } else {
        $quantityPerBlock = 1.0 / $yieldPerBag;
    }
    $quantityPerBlock = round($quantityPerBlock, 4);

    try {
        $pdo->beginTransaction();
        $existing = $pdo->prepare("SELECT id FROM block_bom WHERE block_type_id=? AND material_id=?");
        $existing->execute([(int) $blockTypeId, (int) $cement['id']]);
        if ($existing->fetchColumn()) {
            $pdo->prepare("UPDATE block_bom SET quantity_per_block=?, notes=? WHERE block_type_id=? AND material_id=?")
                ->execute([
                    $quantityPerBlock,
                    'Auto-synced from block yield: ' . $yieldPerBag . ' blocks per bag',
                    (int) $blockTypeId,
                    (int) $cement['id'],
                ]);
        } else {
            $pdo->prepare("INSERT INTO block_bom(block_type_id, material_id, quantity_per_block, notes) VALUES(?,?,?,?)")
                ->execute([
                    (int) $blockTypeId,
                    (int) $cement['id'],
                    $quantityPerBlock,
                    'Auto-synced from block yield: ' . $yieldPerBag . ' blocks per bag',
                ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}
