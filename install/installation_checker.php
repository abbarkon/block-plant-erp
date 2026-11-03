<?php
// Installation Checker - Verify system requirements and configuration

$requirements = [];
$errors = [];
$warnings = [];

// Check PHP version
$phpVersion = phpversion();
$requirements['PHP Version'] = $phpVersion;
if (version_compare($phpVersion, '7.4.0', '>=')) {
    $requirements['PHP Version Status'] = '✅ OK';
} else {
    $errors[] = "PHP version 7.4 or higher required. Current: $phpVersion";
}

// Check required extensions
$extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'session'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        $requirements["Extension: $ext"] = '✅ Loaded';
    } else {
        $errors[] = "Required PHP extension '$ext' is not loaded";
    }
}

// Check database connection
try {
    require_once "../config/database.php";
    $requirements['Database Connection'] = '✅ Connected';
    
    // Check if tables exist
    $tables = ['users', 'block_types', 'raw_materials', 'customers', 'suppliers'];
    $stmt = $pdo->query("SHOW TABLES");
    $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        if (in_array($table, $existing_tables)) {
            $requirements["Table: $table"] = '✅ Exists';
        } else {
            $warnings[] = "Table '$table' not found. Please import database.sql";
        }
    }
} catch (Exception $e) {
    $errors[] = "Database connection failed: " . $e->getMessage();
    $requirements['Database Connection'] = '❌ Failed';
}

// Check write permissions
$directories = ['../assets', '../uploads'];
foreach ($directories as $dir) {
    if (is_writable($dir)) {
        $requirements["Writable: $dir"] = '✅ Yes';
    } else {
        $warnings[] = "Directory '$dir' is not writable";
    }
}

// Check memory limit
$memory = ini_get('memory_limit');
$requirements['Memory Limit'] = $memory;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Block Plant ERP - Installation Checker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .header {
            background: #2c3e50;
            color: white;
            padding: 30px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .content {
            background: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .section {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 10px 0;
            border-radius: 3px;
            color: #721c24;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 10px 0;
            border-radius: 3px;
            color: #856404;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 10px 0;
            border-radius: 3px;
            color: #155724;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #2c3e50;
            color: white;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🧱 Block Plant ERP System</h1>
        <h2>Installation Checker</h2>
    </div>
    
    <div class="content">
        <?php if (empty($errors)): ?>
            <div class="success">
                <h3>✅ System Check Passed!</h3>
                <p>Your server meets all the requirements to run Block Plant ERP.</p>
            </div>
        <?php else: ?>
            <div class="error">
                <h3>❌ System Check Failed</h3>
                <p>Please fix the following errors before proceeding:</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($warnings)): ?>
            <div class="warning">
                <h3>⚠️ Warnings</h3>
                <ul>
                    <?php foreach ($warnings as $warning): ?>
                        <li><?= htmlspecialchars($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="section">
            <h3>System Requirements Check</h3>
            <table>
                <thead>
                    <tr>
                        <th>Requirement</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requirements as $req => $status): ?>
                        <tr>
                            <td><?= htmlspecialchars($req) ?></td>
                            <td><?= $status ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="section">
            <h3>Next Steps</h3>
            <?php if (empty($errors) && empty($warnings)): ?>
                <p><strong>Perfect! Your system is ready.</strong></p>
                <ol>
                    <li>Go to the login page</li>
                    <li>Login with: <code>admin / password</code></li>
                    <li>Change your password immediately</li>
                    <li>Configure company settings</li>
                </ol>
                <a href="../auth/login.php" class="btn btn-success">Go to Login Page</a>
            <?php elseif (empty($errors)): ?>
                <p><strong>System is ready with minor warnings.</strong></p>
                <p>You can proceed, but consider fixing the warnings above.</p>
                <a href="../auth/login.php" class="btn">Proceed to Login</a>
            <?php else: ?>
                <p><strong>Please fix all errors before proceeding.</strong></p>
                <ol>
                    <li>Fix the errors listed above</li>
                    <li>Refresh this page to recheck</li>
                    <li>Ensure MySQL is running</li>
                    <li>Import database.sql if not done</li>
                </ol>
                <a href="installation_checker.php" class="btn">Recheck System</a>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h3>Installation Guide</h3>
            <p>For detailed installation instructions, see:</p>
            <ul>
                <li><strong>README_INSTALL.txt</strong> - Quick installation guide</li>
                <li><strong>README.md</strong> - Complete documentation</li>
                <li><strong>QUICK_START.md</strong> - Quick start guide</li>
            </ul>
        </div>
    </div>
</body>
</html>
