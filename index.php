<?php
/**
 * Cross-Engine DB Schema Sync & Migration Generator (PHP 8.4)
 * Features: Smart Rename Detection & Foreign Key Synchronization
 */
session_start();

$error = null;
$diff = null;
$masterScript = '';
$individualScripts = ['new' => [], 'altered' => []];
$dbStats = ['db1' => [], 'db2' => []];

$action = $_GET['action'] ?? 'form';

function formatBytes($bytes, $precision = 2) {
    if (!$bytes) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// SQL Sanitizer for cross-engine compatibility (MySQL -> MariaDB)
function sanitizeSqlForMariaDB($sql) {
    $sql = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $sql);
    $sql = str_replace('utf8mb3', 'utf8mb4', $sql);
    $sql = str_ireplace(' VISIBLE', '', $sql);
    $sql = str_ireplace(' DEFAULT_GENERATED', '', $sql);
    return $sql;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'compare') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db1Config = filter_input_array(INPUT_POST)['db1'] ?? [];
        $db2Config = filter_input_array(INPUT_POST)['db2'] ?? [];
        $_SESSION['db1'] = $db1Config;
        $_SESSION['db2'] = $db2Config;
    } else {
        $db1Config = $_SESSION['db1'] ?? [];
        $db2Config = $_SESSION['db2'] ?? [];
    }

    if (!empty($db1Config['name']) && !empty($db2Config['name'])) {
        try {
            $pdo1 = new PDO("mysql:host={$db1Config['host']};dbname={$db1Config['name']};charset=utf8mb4", $db1Config['user'], $db1Config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $pdo2 = new PDO("mysql:host={$db2Config['host']};dbname={$db2Config['name']};charset=utf8mb4", $db2Config['user'], $db2Config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // 1. Gather Database Stats
            $stmtSize1 = $pdo1->prepare("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.tables WHERE TABLE_SCHEMA = ?");
            $stmtSize1->execute([$db1Config['name']]);
            $dbStats['db1'] = [
                'version' => $pdo1->query("SELECT VERSION()")->fetchColumn(),
                'size' => formatBytes((float)$stmtSize1->fetchColumn())
            ];

            $stmtSize2 = $pdo2->prepare("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.tables WHERE TABLE_SCHEMA = ?");
            $stmtSize2->execute([$db2Config['name']]);
            $dbStats['db2'] = [
                'version' => $pdo2->query("SELECT VERSION()")->fetchColumn(),
                'size' => formatBytes((float)$stmtSize2->fetchColumn())
            ];

            // 2. Deep Schema Extractor (Tables, Columns, Indexes, Foreign Keys)
            $getSchema = function (PDO $pdo, string $dbName): array {
                $schema = ['tables' => [], 'columns' => [], 'indexes' => [], 'fks' => [], 'counts' => []];
                
                // Tables
                $stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
                $stmt->execute([$dbName]);
                foreach ($stmt->fetchAll() as $row) {
                    $schema['tables'][] = $row['TABLE_NAME'];
                    $schema['counts'][$row['TABLE_NAME']] = $row['TABLE_ROWS'] ?? 0;
                }

                // Columns
                $stmt = $pdo->prepare("
                    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, ORDINAL_POSITION
                    FROM information_schema.columns 
                    WHERE TABLE_SCHEMA = ?
                    ORDER BY ORDINAL_POSITION
                ");
                $stmt->execute([$dbName]);
                foreach ($stmt->fetchAll() as $row) {
                    $schema['columns'][$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
                }

                // Indexes
                foreach ($schema['tables'] as $table) {
                    $stmt = $pdo->query("SHOW INDEXES FROM `" . str_replace('`', '``', $table) . "`");
                    foreach ($stmt->fetchAll() as $idx) {
                        // Skip primary keys that are just part of FKs unless they are distinct indexes
                        $schema['indexes'][$table][$idx['Key_name']]['non_unique'] = $idx['Non_unique'];
                        $schema['indexes'][$table][$idx['Key_name']]['columns'][] = $idx['Column_name'];
                    }
                }

                // Foreign Keys
                $stmt = $pdo->prepare("
                    SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
                    FROM information_schema.KEY_COLUMN_USAGE kcu
                    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                      ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
                      AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                    WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                    ORDER BY kcu.ORDINAL_POSITION
                ");
                $stmt->execute([$dbName]);
                foreach ($stmt->fetchAll() as $row) {
                    $tName = $row['TABLE_NAME'];
                    $fkName = $row['CONSTRAINT_NAME'];
                    if (!isset($schema['fks'][$tName][$fkName])) {
                        $schema['fks'][$tName][$fkName] = [
                            'cols' => [],
                            'ref_table' => $row['REFERENCED_TABLE_NAME'],
                            'ref_cols' => [],
                            'on_update' => $row['UPDATE_RULE'],
                            'on_delete' => $row['DELETE_RULE'],
                        ];
                    }
                    $schema['fks'][$tName][$fkName]['cols'][] = $row['COLUMN_NAME'];
                    $schema['fks'][$tName][$fkName]['ref_cols'][] = $row['REFERENCED_COLUMN_NAME'];
                }

                return $schema;
            };

            $schema1 = $getSchema($pdo1, $db1Config['name']);
            $schema2 = $getSchema($pdo2, $db2Config['name']);

            $dbStats['db1']['tableCount'] = count($schema1['tables']);
            $dbStats['db2']['tableCount'] = count($schema2['tables']);

            // 3. Diff Processing
            $tablesOnlyIn1 = array_diff($schema1['tables'], $schema2['tables']);
            $tablesOnlyIn2 = array_diff($schema2['tables'], $schema1['tables']);
            $sharedTables  = array_intersect($schema1['tables'], $schema2['tables']);

            $columnDiffs = [];
            $indexDiffs = [];
            $fkDiffs = [];
            
            $buildColDef = function ($col, $pdo) {
                $def = $col['COLUMN_TYPE'];
                if ($col['CHARACTER_SET_NAME']) $def .= " CHARACTER SET {$col['CHARACTER_SET_NAME']}";
                if ($col['COLLATION_NAME']) $def .= " COLLATE {$col['COLLATION_NAME']}";
                $def .= ($col['IS_NULLABLE'] === 'NO') ? " NOT NULL" : " NULL";
                
                if ($col['COLUMN_DEFAULT'] !== null) {
                    $def .= (str_contains(strtoupper($col['COLUMN_DEFAULT']), 'CURRENT_TIMESTAMP')) 
                        ? " DEFAULT {$col['COLUMN_DEFAULT']}" 
                        : " DEFAULT " . $pdo->quote($col['COLUMN_DEFAULT']);
                } elseif ($col['IS_NULLABLE'] === 'YES' && !str_contains(strtolower($col['EXTRA']), 'auto_increment')) {
                    $def .= " DEFAULT NULL";
                }
                if ($col['EXTRA']) $def .= " {$col['EXTRA']}";
                return sanitizeSqlForMariaDB($def);
            };

            foreach ($sharedTables as $table) {
                $cols1 = $schema1['columns'][$table] ?? [];
                $cols2 = $schema2['columns'][$table] ?? [];

                $missingColsRaw = array_diff(array_keys($cols1), array_keys($cols2));
                $ignoredColsRaw = array_diff(array_keys($cols2), array_keys($cols1));
                $sharedCols     = array_intersect(array_keys($cols1), array_keys($cols2));
                
                $modifiedCols = [];
                $renamedCols = [];
                
                // Smart Rename Detection
                $missingCols = array_values($missingColsRaw);
                $ignoredCols = array_values($ignoredColsRaw);
                $remainingMissing = [];
                
                // Pass 1: Try matching by exact ORDINAL_POSITION
                foreach ($missingCols as $mCol) {
                    $matched = false;
                    $mPos = $cols1[$mCol]['ORDINAL_POSITION'];
                    foreach ($ignoredCols as $iKey => $iCol) {
                        if (isset($cols2[$iCol]) && $cols2[$iCol]['ORDINAL_POSITION'] === $mPos) {
                            $renamedCols[$iCol] = $mCol; // [oldName => newName]
                            unset($ignoredCols[$iKey]);
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $remainingMissing[] = $mCol;
                    }
                }
                
                // Pass 2: If exactly 1 missing and 1 ignored remains, assume it's a rename regardless of position shift
                $ignoredCols = array_values($ignoredCols);
                if (count($remainingMissing) === 1 && count($ignoredCols) === 1) {
                    $renamedCols[$ignoredCols[0]] = $remainingMissing[0];
                    $remainingMissing = [];
                    $ignoredCols = [];
                }
                
                $missingCols = $remainingMissing;

                // Detect Modified Columns
                foreach ($sharedCols as $colName) {
                    $def1 = $buildColDef($cols1[$colName], $pdo1);
                    $def2 = $buildColDef($cols2[$colName], $pdo2);
                    if ($def1 !== $def2) {
                        $modifiedCols[$colName] = ['dev' => $def1, 'prod' => $def2];
                    }
                }

                if ($missingCols || $ignoredCols || $modifiedCols || $renamedCols) {
                    $columnDiffs[$table] = compact('missingCols', 'ignoredCols', 'modifiedCols', 'renamedCols');
                }

                // Detect Indexes
                $idx1 = $schema1['indexes'][$table] ?? [];
                $idx2 = $schema2['indexes'][$table] ?? [];
                $missingIndexes = [];
                
                // Ignore indexes that are auto-generated for FKs if we are handling FKs separately, 
                // but for safety we process all missing indexes.
                foreach ($idx1 as $idxName => $idxData) {
                    if (!isset($idx2[$idxName]) || $idx1[$idxName]['columns'] !== $idx2[$idxName]['columns']) {
                        $missingIndexes[$idxName] = $idxData;
                    }
                }
                if ($missingIndexes) $indexDiffs[$table] = $missingIndexes;

                // Detect Foreign Keys
                $f1 = $schema1['fks'][$table] ?? [];
                $f2 = $schema2['fks'][$table] ?? [];
                $fDiffs = ['missing' => [], 'modified' => []];
                foreach ($f1 as $fkName => $fkData) {
                    if (!isset($f2[$fkName])) {
                        $fDiffs['missing'][$fkName] = $fkData;
                    } elseif ($fkData !== $f2[$fkName]) {
                        $fDiffs['modified'][$fkName] = $fkData;
                    }
                }
                if (!empty($fDiffs['missing']) || !empty($fDiffs['modified'])) {
                    $fkDiffs[$table] = $fDiffs;
                }
            }

            // 4. Generate SQL Scripts
            $masterLines = [
                "-- =====================================================================",
                "-- MASTER SCHEMA MIGRATION SCRIPT",
                "-- Source (Dev)  : {$db1Config['name']} @ {$db1Config['host']} ({$dbStats['db1']['version']})",
                "-- Target (Prod) : {$db2Config['name']} @ {$db2Config['host']} ({$dbStats['db2']['version']})",
                "-- Generated On  : " . date('Y-m-d H:i:s'),
                "-- Features      : Cross-engine compatible, Rename detection, FK sync.",
                "-- =====================================================================",
                "",
                "SET FOREIGN_KEY_CHECKS=0;",
                ""
            ];

            // New Tables SQL
            if (!empty($tablesOnlyIn1)) {
                $masterLines[] = "-- ---------------------------------------------------------------------";
                $masterLines[] = "-- 1. NEW TABLES";
                $masterLines[] = "-- ---------------------------------------------------------------------\n";
                foreach ($tablesOnlyIn1 as $table) {
                    $stmt = $pdo1->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`");
                    $createRow = $stmt->fetch(PDO::FETCH_NUM);
                    $createSql = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createRow[1]);
                    $createSql = sanitizeSqlForMariaDB($createSql) . ";";
                    
                    $individualScripts['new'][$table] = "SET FOREIGN_KEY_CHECKS=0;\n{$createSql}\nSET FOREIGN_KEY_CHECKS=1;";
                    $masterLines[] = $createSql . "\n";
                }
            }

            // Altered Tables SQL
            $structuralChanges = false;
            foreach ($sharedTables as $table) {
                $hasMod = !empty($columnDiffs[$table]['modifiedCols']);
                $hasNew = !empty($columnDiffs[$table]['missingCols']);
                $hasRen = !empty($columnDiffs[$table]['renamedCols']);
                $hasIdx = !empty($indexDiffs[$table]);
                $hasFk  = !empty($fkDiffs[$table]);

                if ($hasMod || $hasNew || $hasRen || $hasIdx || $hasFk) {
                    if (!$structuralChanges) {
                        $masterLines[] = "-- ---------------------------------------------------------------------";
                        $masterLines[] = "-- 2. ALTER EXISTING TABLES";
                        $masterLines[] = "-- ---------------------------------------------------------------------\n";
                        $structuralChanges = true;
                    }

                    $tableAlterLines = [];

                    // Handle Renames
                    if ($hasRen) {
                        foreach ($columnDiffs[$table]['renamedCols'] as $oldCol => $newCol) {
                            $colData = $schema1['columns'][$table][$newCol];
                            $def = $buildColDef($colData, $pdo2);
                            $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` CHANGE `" . str_replace('`', '``', $oldCol) . "` `" . str_replace('`', '``', $newCol) . "` {$def};";
                        }
                    }

                    // Handle Missing Columns
                    if ($hasNew) {
                        foreach ($columnDiffs[$table]['missingCols'] as $colName) {
                            $colData = $schema1['columns'][$table][$colName];
                            $def = $buildColDef($colData, $pdo2);
                            $allDevCols = array_keys($schema1['columns'][$table]);
                            $colIndex = array_search($colName, $allDevCols);
                            $position = ($colIndex === 0) ? "FIRST" : "AFTER `" . $allDevCols[$colIndex - 1] . "`";
                            $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD COLUMN `" . str_replace('`', '``', $colName) . "` {$def} {$position};";
                        }
                    }

                    // Handle Modified Columns
                    if ($hasMod) {
                        foreach ($columnDiffs[$table]['modifiedCols'] as $colName => $defs) {
                            $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` MODIFY COLUMN `" . str_replace('`', '``', $colName) . "` {$defs['dev']};";
                        }
                    }

                    // Handle Indexes
                    if ($hasIdx) {
                        foreach ($indexDiffs[$table] as $idxName => $idxData) {
                            $cols = "`" . implode("`, `", $idxData['columns']) . "`";
                            if ($idxName === 'PRIMARY') {
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD PRIMARY KEY ({$cols});";
                            } elseif ($idxData['non_unique'] == 0) {
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD UNIQUE INDEX `" . str_replace('`', '``', $idxName) . "` ({$cols});";
                            } else {
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD INDEX `" . str_replace('`', '``', $idxName) . "` ({$cols});";
                            }
                        }
                    }

                    // Handle Foreign Keys
                    if ($hasFk) {
                        if (!empty($fkDiffs[$table]['modified'])) {
                            foreach ($fkDiffs[$table]['modified'] as $fkName => $fk) {
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` DROP FOREIGN KEY `" . str_replace('`', '``', $fkName) . "`;";
                                $cols = "`" . implode("`, `", $fk['cols']) . "`";
                                $refCols = "`" . implode("`, `", $fk['ref_cols']) . "`";
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD CONSTRAINT `" . str_replace('`', '``', $fkName) . "` FOREIGN KEY ({$cols}) REFERENCES `" . str_replace('`', '``', $fk['ref_table']) . "` ({$refCols}) ON UPDATE {$fk['on_update']} ON DELETE {$fk['on_delete']};";
                            }
                        }
                        if (!empty($fkDiffs[$table]['missing'])) {
                            foreach ($fkDiffs[$table]['missing'] as $fkName => $fk) {
                                $cols = "`" . implode("`, `", $fk['cols']) . "`";
                                $refCols = "`" . implode("`, `", $fk['ref_cols']) . "`";
                                $tableAlterLines[] = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD CONSTRAINT `" . str_replace('`', '``', $fkName) . "` FOREIGN KEY ({$cols}) REFERENCES `" . str_replace('`', '``', $fk['ref_table']) . "` ({$refCols}) ON UPDATE {$fk['on_update']} ON DELETE {$fk['on_delete']};";
                            }
                        }
                    }

                    $combinedAlters = sanitizeSqlForMariaDB(implode("\n", $tableAlterLines));
                    $individualScripts['altered'][$table] = "SET FOREIGN_KEY_CHECKS=0;\n{$combinedAlters}\nSET FOREIGN_KEY_CHECKS=1;";
                    $masterLines[] = $combinedAlters . "\n";
                }
            }

            $masterLines[] = "SET FOREIGN_KEY_CHECKS=1;";
            $masterScript = implode("\n", $masterLines);
            $diff = compact('schema1', 'schema2', 'tablesOnlyIn1', 'tablesOnlyIn2', 'sharedTables', 'columnDiffs', 'indexDiffs', 'fkDiffs', 'db1Config', 'db2Config');$action = 'results';

        } catch (PDOException $e) {$error = "Database Error: " . htmlspecialchars($e->getMessage());$action = 'form';
        }
    }
}

$db1Form =$_SESSION['db1'] ?? ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => ''];
$db2Form =$_SESSION['db2'] ?? ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cross-Engine Schema Syncer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
    <script>
        function openSqlModal(tableName, sqlElementId) {
            document.getElementById('modalTitle').innerText = "Migration SQL: " + tableName;
            document.getElementById('modalTextarea').value = document.getElementById(sqlElementId).value;
            document.getElementById('sqlModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeSqlModal() {
            document.getElementById('sqlModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function copyModalSql(btn) {
            var text = document.getElementById('modalTextarea').value;
            navigator.clipboard.writeText(text).then(function() {
                var originalText = btn.innerHTML;
                btn.innerHTML = "✓ Copied!";
                btn.classList.add('bg-emerald-700');
                btn.classList.remove('bg-emerald-600');
                setTimeout(() => { 
                    btn.innerHTML = originalText; 
                    btn.classList.remove('bg-emerald-700');
                    btn.classList.add('bg-emerald-600');
                }, 2000);
            });
        }

        function toggleMasterSql(btn) {
            const container = document.getElementById('masterSqlContainer');
            container.classList.remove('hidden');
            btn.style.display = 'none';
            setTimeout(() => { container.scrollIntoView({ behavior: 'smooth' }); }, 100);
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased p-6 md:p-10 pb-32 relative">

<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Database Syncer</h1>
            <p class="text-slate-500 mt-1">Cross-engine compatible (MySQL ↔ MariaDB) structural syncing.</p>
        </div>
        <?php if ($action === 'results'): ?>
            <a href="index.php" class="bg-white border border-slate-300 text-slate-700 px-4 py-2 rounded-lg hover:bg-slate-50 shadow-sm font-medium transition-colors">
                &larr; Start Over
            </a>
        <?php endif; ?>
    </div>

    <!-- Data Warning Banner -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-8 rounded-r-lg shadow-sm flex items-start gap-4">
        <svg class="w-6 h-6 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <div>
            <h3 class="text-blue-900 font-bold">Important: Structural Migration Only</h3>
            <p class="text-blue-800 text-sm mt-1">This tool generates <strong>clean, engine-agnostic SQL</strong> specifically for replicating database structures. It does <strong>not</strong> export or migrate your actual table data.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-8 rounded-r-lg shadow-sm font-medium text-red-700">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'form'): ?>
        <!-- FORM -->
        <form action="?action=compare" method="POST" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- SOURCE -->
                <div class="space-y-4">
                    <h2 class="text-xl font-semibold text-indigo-600">Source (Dev / Blueprint)</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Host</label><input type="text" name="db1[host]" value="<?= htmlspecialchars($db1Form['host']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"></div>
                        <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Database Name</label><input type="text" name="db1[name]" value="<?= htmlspecialchars($db1Form['name']) ?>" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">Username</label><input type="text" name="db1[user]" value="<?= htmlspecialchars($db1Form['user']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">Password</label><input type="password" name="db1[pass]" value="<?= htmlspecialchars($db1Form['pass']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"></div>
                    </div>
                </div>

                <!-- TARGET -->
                <div class="space-y-4">
                    <h2 class="text-xl font-semibold text-emerald-600">Target (Prod / To Update)</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Host</label><input type="text" name="db2[host]" value="<?= htmlspecialchars($db2Form['host']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"></div>
                        <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Database Name</label><input type="text" name="db2[name]" value="<?= htmlspecialchars($db2Form['name']) ?>" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">Username</label><input type="text" name="db2[user]" value="<?= htmlspecialchars($db2Form['user']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">Password</label><input type="password" name="db2[pass]" value="<?= htmlspecialchars($db2Form['pass']) ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"></div>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-6 border-t border-slate-100 flex justify-end">
                <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2.5 px-6 rounded-lg shadow-md transition-all">
                    Run Analysis & Generate Scripts
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($action === 'results' &&$diff): ?>
        
        <!-- DATABASE STATISTICS -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-indigo-200 p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-xs uppercase tracking-wider font-bold text-indigo-500 mb-1">Source (Dev)</h3>
                    <p class="text-lg font-black text-slate-800"><?= htmlspecialchars($diff['db1Config']['name']) ?></p>
                    <p class="text-sm text-slate-500 font-mono mt-1" title="Engine Version"><?= htmlspecialchars($dbStats['db1']['version']) ?></p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-black text-indigo-600"><?= $dbStats['db1']['size'] ?></div>
                    <div class="text-sm text-slate-500"><?= number_format($dbStats['db1']['tableCount']) ?> Tables</div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-emerald-200 p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-xs uppercase tracking-wider font-bold text-emerald-500 mb-1">Target (Prod)</h3>
                    <p class="text-lg font-black text-slate-800"><?= htmlspecialchars($diff['db2Config']['name']) ?></p>
                    <p class="text-sm text-slate-500 font-mono mt-1" title="Engine Version"><?= htmlspecialchars($dbStats['db2']['version']) ?></p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-black text-emerald-600"><?= $dbStats['db2']['size'] ?></div>
                    <div class="text-sm text-slate-500"><?= number_format($dbStats['db2']['tableCount']) ?> Tables</div>
                </div>
            </div>
        </div>

        <!-- MISSING TABLES -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-indigo-50 border-b border-indigo-100 px-6 py-4">
                    <h3 class="text-lg font-bold text-indigo-800">Tables Only in <?= htmlspecialchars($diff['db1Config']['name']) ?> (To Add)</h3>
                </div>
                <ul class="divide-y divide-slate-100">
                    <?php if(empty($diff['tablesOnlyIn1'])) echo '<li class="p-6 text-slate-500 italic">None</li>'; ?>
                    <?php foreach ($diff['tablesOnlyIn1'] as$table): ?>
                        <li class="px-6 py-3 flex justify-between items-center hover:bg-slate-50">
                            <span class="font-mono text-sm text-slate-800"><?= htmlspecialchars($table) ?></span>
                            <?php $hash = md5('new_'.$table); ?>
                            <textarea id="sql_<?= $hash ?>" class="hidden"><?= htmlspecialchars($individualScripts['new'][$table]) ?></textarea>
                            <button onclick="openSqlModal('<?= htmlspecialchars($table) ?>', 'sql_<?=$hash ?>')" class="text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-3 py-1.5 rounded shadow-sm font-medium transition-colors">
                                View SQL
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-emerald-50 border-b border-emerald-100 px-6 py-4">
                    <h3 class="text-lg font-bold text-emerald-800">Tables Only in <?= htmlspecialchars($diff['db2Config']['name']) ?> (Ignored)</h3>
                </div>
                <ul class="divide-y divide-slate-100">
                    <?php if(empty($diff['tablesOnlyIn2'])) echo '<li class="p-6 text-slate-500 italic">None</li>'; ?>
                    <?php foreach ($diff['tablesOnlyIn2'] as$table): ?>
                        <li class="px-6 py-3 flex justify-between items-center opacity-60">
                            <span class="font-mono text-sm text-slate-800 line-through"><?= htmlspecialchars($table) ?></span>
                            <span class="text-xs bg-slate-200 text-slate-600 px-2 py-1 rounded font-medium">Safe</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- STRUCTURAL DIFFERENCES -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-12">
            <div class="bg-slate-100 border-b border-slate-200 px-6 py-4 flex justify-between items-center">
                <h3 class="text-lg font-bold text-slate-800">Deep Structural Discrepancies (Shared Tables)</h3>
            </div>
            
            <!-- CAUTION BANNER -->
            <div class="bg-amber-50 border-b border-amber-200 px-6 py-3 flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div>
                    <h4 class="text-amber-900 font-semibold text-sm">Caution: Safe Update Mode</h4>
                    <p class="text-amber-700 text-xs mt-0.5">These tables will be structurally updated. To guarantee zero data loss, this tool <strong>never</strong> deletes columns. Columns that exist in the target database but not in the source will be retained indefinitely, which may lead to orphaned schema over time.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                            <th class="px-6 py-3 font-semibold border-b border-slate-200">Table Name</th>
                            <th class="px-6 py-3 font-semibold border-b border-slate-200">Deviations Found</th>
                            <th class="px-6 py-3 font-semibold border-b border-slate-200 text-right">Specific Export</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php 
                        $hasAnyChanges = false;
                        foreach ($diff['sharedTables'] as $table):$cDiff = $diff['columnDiffs'][$table] ?? null;
                            $iDiff = $diff['indexDiffs'][$table] ?? null;
                            $fDiff = $diff['fkDiffs'][$table] ?? null;
                            if (!$cDiff && !$iDiff && !$fDiff) continue;
                            $hasAnyChanges = true;
                        ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-mono text-sm font-bold text-slate-800 align-top">
                                    <?= htmlspecialchars($table) ?>
                                </td>
                                <td class="px-6 py-4 space-y-4">
                                    
                                    <!-- Renames -->
                                    <?php if (!empty($cDiff['renamedCols'])): ?>
                                        <div>
                                            <span class="text-purple-600 font-semibold text-xs uppercase tracking-wider block mb-1">🔄 Renamed Columns</span>
                                            <div class="space-y-1">
                                                <?php foreach ($cDiff['renamedCols'] as $old =>$new): ?>
                                                    <div class="bg-purple-50 border border-purple-200 p-2 rounded text-xs font-mono">
                                                        <span class="text-slate-500 line-through"><?= htmlspecialchars($old) ?></span> &rarr; <span class="text-purple-700 font-bold"><?= htmlspecialchars($new) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Missing Columns -->
                                    <?php if (!empty($cDiff['missingCols'])): ?>
                                        <div>
                                            <span class="text-emerald-600 font-semibold text-xs uppercase tracking-wider block mb-1">✅ Missing Columns (To Add)</span>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($cDiff['missingCols'] as$col): ?>
                                                    <span class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs px-2 py-1 rounded font-mono">+ <?= htmlspecialchars($col) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Modified Columns -->
                                    <?php if (!empty($cDiff['modifiedCols'])): ?>
                                        <div>
                                            <span class="text-amber-600 font-semibold text-xs uppercase tracking-wider block mb-1">⚠️ Modified Columns (To Update)</span>
                                            <div class="space-y-1">
                                                <?php foreach ($cDiff['modifiedCols'] as $col =>$changes): ?>
                                                    <div class="bg-amber-50 border border-amber-200 p-2 rounded text-xs font-mono">
                                                        <div class="font-bold text-amber-900 mb-1"><?= htmlspecialchars($col) ?></div>
                                                        <div class="text-slate-500 line-through">Prod: <?= htmlspecialchars($changes['prod']) ?></div>
                                                        <div class="text-emerald-600">Dev: <?= htmlspecialchars($changes['dev']) ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Foreign Keys -->
                                    <?php if (!empty($fDiff)): ?>
                                        <div>
                                            <span class="text-pink-600 font-semibold text-xs uppercase tracking-wider block mb-1">🔗 Foreign Keys (To Sync)</span>
                                            <div class="flex flex-wrap gap-1">
                                                <?php if(!empty($fDiff['missing'])): foreach ($fDiff['missing'] as $fkName =>$fk): ?>
                                                    <span class="bg-pink-50 border border-pink-200 text-pink-700 text-xs px-2 py-1 rounded font-mono" title="Will Add">+ <?= htmlspecialchars($fkName) ?></span>
                                                <?php endforeach; endif; ?>
                                                <?php if(!empty($fDiff['modified'])): foreach ($fDiff['modified'] as $fkName =>$fk): ?>
                                                    <span class="bg-pink-50 border border-pink-200 text-pink-700 text-xs px-2 py-1 rounded font-mono" title="Will Recreate">🔄 <?= htmlspecialchars($fkName) ?></span>
                                                <?php endforeach; endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Indexes -->
                                    <?php if (!empty($iDiff)): ?>
                                        <div>
                                            <span class="text-blue-600 font-semibold text-xs uppercase tracking-wider block mb-1">🔑 Missing Indexes/Keys (To Add)</span>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($iDiff as $idxName =>$idxData): ?>
                                                    <span class="bg-blue-50 border border-blue-200 text-blue-700 text-xs px-2 py-1 rounded font-mono">+ <?= htmlspecialchars($idxName) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Extra Columns (Ignored) -->
                                    <?php if (!empty($cDiff['ignoredCols'])): ?>
                                        <div>
                                            <span class="text-slate-400 font-semibold text-xs uppercase tracking-wider block mb-1">🛡️ Extra Columns in Prod (Ignored)</span>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($cDiff['ignoredCols'] as$col): ?>
                                                    <span class="bg-slate-100 border border-slate-200 text-slate-500 text-xs px-2 py-1 rounded font-mono opacity-70"><?= htmlspecialchars($col) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 align-top text-right">
                                    <?php if (isset($individualScripts['altered'][$table])): ?>
                                        <?php $hash = md5('alt_'.$table); ?>
                                        <textarea id="sql_<?= $hash ?>" class="hidden"><?= htmlspecialchars($individualScripts['altered'][$table]) ?></textarea>
                                        <button onclick="openSqlModal('<?= htmlspecialchars($table) ?>', 'sql_<?=$hash ?>')" class="text-sm bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded shadow-sm font-medium transition-colors inline-block whitespace-nowrap">
                                            View Table SQL
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (!$hasAnyChanges): ?>
                            <tr><td colspan="3" class="p-6 text-center text-slate-500 italic">No structural discrepancies found in shared tables!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MASTER SQL TRIGGER -->
        <div class="text-center">
            <button onclick="toggleMasterSql(this)" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-8 rounded-full shadow-lg transition-all flex items-center gap-2 mx-auto">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                Generate & View Master Migration Script
            </button>
        </div>

        <!-- HIDDEN MASTER SCRIPT CONTAINER -->
        <div id="masterSqlContainer" class="hidden mt-8 bg-slate-900 rounded-xl shadow-2xl border border-slate-700 overflow-hidden flex flex-col h-[600px]">
            <div class="bg-slate-800 border-b border-slate-700 px-6 py-4 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-emerald-400">Master Migration Script</h3>
                    <p class="text-slate-400 text-xs mt-1">Cleaned and formatted for cross-engine compatibility.</p>
                </div>
                <button onclick="var btn=this; var text=document.getElementById('masterSqlTextarea').value; navigator.clipboard.writeText(text).then(function(){ var orig=btn.innerHTML; btn.innerHTML='✓ Copied!'; btn.classList.add('bg-emerald-600'); btn.classList.remove('bg-slate-700'); setTimeout(()=>{btn.innerHTML=orig; btn.classList.remove('bg-emerald-600'); btn.classList.add('bg-slate-700');}, 2000); });" class="bg-slate-700 hover:bg-slate-600 text-white text-sm px-4 py-2 rounded transition-colors font-medium">
                    Copy Master Script
                </button>
            </div>
            <textarea id="masterSqlTextarea" class="w-full flex-grow p-6 font-mono text-sm bg-slate-900 text-slate-300 outline-none resize-none" readonly><?= htmlspecialchars($masterScript) ?></textarea>
        </div>
    <?php endif; ?>

</div>

<!-- INDIVIDUAL SQL MODAL (Hidden by default) -->
<div id="sqlModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col max-h-[85vh]">
        <div class="bg-slate-800 px-6 py-4 flex justify-between items-center">
            <h3 id="modalTitle" class="text-lg font-bold text-white">Table SQL</h3>
            <button onclick="closeSqlModal()" class="text-slate-400 hover:text-white text-2xl leading-none">&times;</button>
        </div>
        <textarea id="modalTextarea" class="w-full flex-grow p-6 font-mono text-sm bg-slate-900 text-slate-300 outline-none resize-none min-h-[300px]" readonly></textarea>
        <div class="bg-slate-100 px-6 py-4 flex justify-end gap-3 border-t border-slate-200">
            <button onclick="closeSqlModal()" class="px-5 py-2.5 text-slate-600 hover:text-slate-900 font-medium transition-colors">Cancel</button>
            <button onclick="copyModalSql(this)" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded shadow-sm font-medium transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                Copy SQL
            </button>
        </div>
    </div>
</div>

</body>
</html>