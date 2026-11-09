<?php
/**
 * View a backup before retrieving it, and add its missing records to the
 * system without overwriting anything.
 *
 * The backup file is only parsed here (never executed), so previewing a
 * backup cannot change the database. Adding records only INSERTs rows the
 * system does not have yet; existing records are always kept as they are.
 */

require_once __DIR__ . '/backup_helper.php';

/** Tables that are shown in the preview but never added from a backup. */
const SECSYS_MERGE_VIEW_ONLY_TABLES = [
    'system_settings'         => 'System settings stay as they are now.',
    'password_reset_attempts' => 'Old password reset attempts are not restored.',
];

/** Tables offered for adding but left unticked by default (accounts / login data). */
const SECSYS_MERGE_OPT_IN_TABLES = ['users'];

/** Parents first, so records that point to another record are added after it. */
const SECSYS_MERGE_TABLE_ORDER = [
    'guard_shifts', 'security_guard', 'users', 'registered_vehicles', 'visitor_vehicles',
    'vehicle_logs', 'vehicle_log_dates', 'vehicle_logs_archive', 'visitor_vehicles_archive',
    'registered_vehicles_archive', 'guard_duty_pairs', 'guard_activity', 'security_contacts',
    'exit_without_entry_alerts',
];

/** Friendly names for the preview. */
const SECSYS_MERGE_TABLE_LABELS = [
    'vehicle_logs'                => 'Vehicle Logs (today)',
    'vehicle_logs_archive'        => 'Archived Vehicle Logs',
    'vehicle_log_dates'           => 'Vehicle Visits (time in / out)',
    'visitor_vehicles'            => 'Visitor Vehicles',
    'visitor_vehicles_archive'    => 'Archived Visitor Vehicles',
    'registered_vehicles'         => 'Registered Vehicles',
    'registered_vehicles_archive' => 'Archived Registered Vehicles',
    'security_guard'              => 'Security Guards',
    'guard_shifts'                => 'Guard Shifts',
    'guard_duty_pairs'            => 'Guard Duty Sessions',
    'guard_activity'              => 'Activity / Audit Log',
    'users'                       => 'User Accounts',
    'security_contacts'           => 'Security Contacts',
    'system_settings'             => 'System Settings',
    'password_reset_attempts'     => 'Password Reset Attempts',
    'exit_without_entry_alerts'   => 'Exit Without Entry Alerts',
];

/**
 * A record that was moved between a table and its archive is still in the
 * system. Each entry: the backup column to read, and where it may live now.
 */
function secsysMergeMovedRecordLookups(): array
{
    return [
        'vehicle_logs'                => [['from' => 'id', 'table' => 'vehicle_logs_archive', 'col' => 'id']],
        'vehicle_logs_archive'        => [['from' => 'id', 'table' => 'vehicle_logs', 'col' => 'id']],
        'visitor_vehicles'            => [['from' => 'id', 'table' => 'visitor_vehicles_archive', 'col' => 'original_id']],
        'visitor_vehicles_archive'    => [['from' => 'original_id', 'table' => 'visitor_vehicles', 'col' => 'id']],
        'registered_vehicles'         => [['from' => 'id', 'table' => 'registered_vehicles_archive', 'col' => 'original_id']],
        'registered_vehicles_archive' => [['from' => 'original_id', 'table' => 'registered_vehicles', 'col' => 'id']],
    ];
}

/* ------------------------------------------------------------------
   PARSING (read-only)
------------------------------------------------------------------ */

/**
 * Read the data rows of a .sql backup without executing it.
 *
 * @return array{created_at:string, tables: array<string, array{columns: list<string>, primary: list<string>, rows: list<list<?string>>}>}
 */
function secsysParseBackupData(string $sqlContent): array
{
    if (strncmp($sqlContent, "\xEF\xBB\xBF", 3) === 0) {
        $sqlContent = substr($sqlContent, 3);
    }
    $sqlContent = str_replace(["\r\n", "\r"], "\n", $sqlContent);

    $createdAt = '';
    if (preg_match('/^--\s*Created At:\s*(.+)$/mi', $sqlContent, $cm)) {
        $createdAt = trim($cm[1]);
    }

    $unquote = function (string $name): string {
        return str_replace('``', '`', trim(trim($name), '`'));
    };

    $tables = [];
    foreach (secsysSplitSqlStatements($sqlContent) as $stmt) {
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s*\((.*)\)/is', $stmt, $m)) {
            $table = $unquote($m[1]);
            $columns = [];
            $primary = [];
            foreach (preg_split('/\n/', $m[2]) as $line) {
                $line = trim($line);
                if (preg_match('/^`((?:[^`]|``)+)`\s/', $line, $col)) {
                    $columns[] = str_replace('``', '`', $col[1]);
                } elseif (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $line, $pk)) {
                    foreach (explode(',', $pk[1]) as $p) {
                        $primary[] = $unquote(preg_replace('/\(\d+\)$/', '', trim($p)));
                    }
                }
            }
            $tables[$table] = $tables[$table] ?? ['columns' => [], 'primary' => [], 'rows' => []];
            $tables[$table]['columns'] = $columns;
            $tables[$table]['primary'] = $primary;
            continue;
        }

        if (!preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s*(\(([^)]*)\))?\s*VALUES\s*/is', $stmt, $m)) {
            continue;
        }
        $table = $unquote($m[1]);
        $tables[$table] = $tables[$table] ?? ['columns' => [], 'primary' => [], 'rows' => []];
        $insertColumns = !empty($m[3])
            ? array_map($unquote, explode(',', $m[3]))
            : $tables[$table]['columns'];
        foreach (secsysParseSqlValueTuples(substr($stmt, strlen($m[0]))) as $tuple) {
            if (count($tuple) !== count($insertColumns)) {
                continue;
            }
            $tables[$table]['rows'][] = array_combine($insertColumns, $tuple);
        }
        if (empty($tables[$table]['columns'])) {
            $tables[$table]['columns'] = $insertColumns;
        }
    }

    return ['created_at' => $createdAt, 'tables' => $tables];
}

/**
 * Parse "(1, 'a', NULL), (2, 'b\'c', 3.5)" into rows of strings / nulls.
 *
 * @return list<list<?string>>
 */
function secsysParseSqlValueTuples(string $s): array
{
    $rows = [];
    $len = strlen($s);
    $i = 0;
    $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A"];

    while ($i < $len) {
        while ($i < $len && ($s[$i] === ',' || ctype_space($s[$i]))) {
            $i++;
        }
        if ($i >= $len || $s[$i] !== '(') {
            break;
        }
        $i++;
        $row = [];
        while ($i < $len) {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $ch = $s[$i];
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $i++;
                $buf = '';
                while ($i < $len) {
                    $c = $s[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $n = $s[$i + 1];
                        $buf .= $escapes[$n] ?? $n;
                        $i += 2;
                        continue;
                    }
                    if ($c === $quote) {
                        if ($i + 1 < $len && $s[$i + 1] === $quote) {
                            $buf .= $quote;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                $row[] = $buf;
            } else {
                $start = $i;
                while ($i < $len && $s[$i] !== ',' && $s[$i] !== ')') {
                    $i++;
                }
                $token = trim(substr($s, $start, $i - $start));
                $row[] = strcasecmp($token, 'NULL') === 0 ? null : $token;
            }
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i < $len && $s[$i] === ',') {
                $i++;
                continue;
            }
            if ($i < $len && $s[$i] === ')') {
                $i++;
                break;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

/* ------------------------------------------------------------------
   COMPARING WITH THE SYSTEM (read-only)
------------------------------------------------------------------ */

function secsysMergeQuoteName(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** Columns and primary key of a live table, or null when the table does not exist. */
function secsysMergeLiveTableInfo(mysqli $conn, string $table): ?array
{
    try {
        $res = $conn->query('SHOW COLUMNS FROM ' . secsysMergeQuoteName($table));
    } catch (mysqli_sql_exception $e) {
        return null;
    }
    if (!$res) {
        return null;
    }
    $columns = [];
    $primary = [];
    $autoIncrement = null;
    while ($c = $res->fetch_assoc()) {
        $columns[] = $c['Field'];
        if ($c['Key'] === 'PRI') {
            $primary[] = $c['Field'];
        }
        if (stripos((string)$c['Extra'], 'auto_increment') !== false) {
            $autoIncrement = $c['Field'];
        }
    }
    $unique = [];
    $keys = $conn->query('SHOW KEYS FROM ' . secsysMergeQuoteName($table) . " WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'");
    $byKey = [];
    while ($keys && ($k = $keys->fetch_assoc())) {
        $byKey[$k['Key_name']][] = $k['Column_name'];
    }
    foreach ($byKey as $cols) {
        if (count($cols) === 1) {
            $unique[] = $cols[0];
        }
    }
    return ['columns' => $columns, 'primary' => $primary, 'unique' => $unique, 'auto_increment' => $autoIncrement];
}

/** Map of normalized column value => row, for looking records up by one column. */
function secsysMergeIndexLiveRows(mysqli $conn, string $table, string $column): array
{
    $index = [];
    try {
        $res = $conn->query('SELECT * FROM ' . secsysMergeQuoteName($table));
    } catch (mysqli_sql_exception $e) {
        return [];
    }
    while ($res && ($row = $res->fetch_assoc())) {
        if (array_key_exists($column, $row) && $row[$column] !== null) {
            $index[strtolower(trim((string)$row[$column]))] = $row;
        }
    }
    return $index;
}

/** True when every shared column holds the same value (ignoring login/presence bookkeeping). */
function secsysMergeRowsMatch(array $backupRow, array $liveRow): bool
{
    $ignore = ['is_logged_in', 'last_login', 'last_logout', 'last_seen', 'login_status', 'archived_at', 'archived_by'];
    foreach ($backupRow as $col => $val) {
        if (in_array($col, $ignore, true) || !array_key_exists($col, $liveRow)) {
            continue;
        }
        $a = $val === null ? null : trim((string)$val);
        $b = $liveRow[$col] === null ? null : trim((string)$liveRow[$col]);
        if ($a === $b) {
            continue;
        }
        if ($a !== null && $b !== null && is_numeric($a) && is_numeric($b) && (float)$a === (float)$b) {
            continue;
        }
        return false;
    }
    return true;
}

/**
 * Compare a parsed backup with the system.
 *
 * Each row gets a status:
 *   new       - not in the system; can be added
 *   same      - already in the system, identical
 *   moved     - already in the system, but archived / unarchived since the backup
 *   different - a record with the same ID / plate / username exists with other
 *               values; the system's version is kept
 *
 * @return array<string, array{label:string, columns:list<string>, rows:list<array>, status:list<string>, counts:array, can_add:bool, default_add:bool, note:string}>
 */
function secsysCompareBackupWithSystem(mysqli $conn, array $parsed): array
{
    $moved = secsysMergeMovedRecordLookups();
    $result = [];
    $indexCache = [];
    $index = function (string $table, string $column) use ($conn, &$indexCache) {
        $key = $table . '.' . $column;
        if (!isset($indexCache[$key])) {
            $indexCache[$key] = secsysMergeIndexLiveRows($conn, $table, $column);
        }
        return $indexCache[$key];
    };

    foreach ($parsed['tables'] as $table => $data) {
        $live = secsysMergeLiveTableInfo($conn, $table);
        $counts = ['total' => count($data['rows']), 'new' => 0, 'same' => 0, 'moved' => 0, 'different' => 0];
        $statuses = [];
        $note = '';
        $canAdd = true;

        if ($live === null) {
            $canAdd = false;
            $note = 'This table is not used by the system anymore.';
        } elseif (isset(SECSYS_MERGE_VIEW_ONLY_TABLES[$table])) {
            $canAdd = false;
            $note = SECSYS_MERGE_VIEW_ONLY_TABLES[$table];
        } elseif (empty($live['primary'])) {
            $canAdd = false;
            $note = 'This table has no record ID, so it is shown for viewing only.';
        }

        $pk = $live['primary'] ?? [];
        $pkCol = count($pk) === 1 ? $pk[0] : null;
        $matchIds = [];

        foreach ($data['rows'] as $row) {
            $status = 'new';
            $liveMatch = null;
            if ($live !== null) {
                if ($pkCol !== null && isset($row[$pkCol]) && $row[$pkCol] !== null) {
                    $liveMatch = $index($table, $pkCol)[strtolower(trim((string)$row[$pkCol]))] ?? null;
                }
                foreach ($live['unique'] as $uCol) {
                    if ($liveMatch === null && isset($row[$uCol]) && $row[$uCol] !== null && $row[$uCol] !== '') {
                        $liveMatch = $index($table, $uCol)[strtolower(trim((string)$row[$uCol]))] ?? null;
                    }
                }
                if ($liveMatch !== null) {
                    $status = secsysMergeRowsMatch($row, $liveMatch) ? 'same' : 'different';
                } else {
                    foreach ($moved[$table] ?? [] as $lookup) {
                        $val = $row[$lookup['from']] ?? null;
                        if ($val !== null && isset($index($lookup['table'], $lookup['col'])[strtolower(trim((string)$val))])) {
                            $status = 'moved';
                            break;
                        }
                    }
                }
            }
            $counts[$status]++;
            $statuses[] = $status;
            // ID of the system's record, so an "updated since" row can be put back to the backup's version
            $matchIds[] = ($status === 'different' && $pkCol !== null && $liveMatch !== null) ? $liveMatch[$pkCol] : null;
        }

        $canRestore = $canAdd && $pkCol !== null && $counts['different'] > 0;
        $result[$table] = [
            'label'       => SECSYS_MERGE_TABLE_LABELS[$table] ?? ucwords(str_replace('_', ' ', $table)),
            'columns'     => $data['columns'] ?: (isset($data['rows'][0]) ? array_keys($data['rows'][0]) : []),
            'rows'        => $data['rows'],
            'status'      => $statuses,
            'match_ids'   => $matchIds,
            'primary'     => $pkCol,
            'counts'      => $counts,
            'can_add'     => $canAdd && $counts['new'] > 0,
            'can_restore' => $canRestore,
            'default_add' => $canAdd && $counts['new'] > 0 && !in_array($table, SECSYS_MERGE_OPT_IN_TABLES, true),
            'note'        => $note,
        ];
    }

    // Show tables in a sensible order: system data first, then the rest alphabetically
    $order = array_flip(SECSYS_MERGE_TABLE_ORDER);
    uksort($result, function ($a, $b) use ($order) {
        $ra = $order[$a] ?? 1000;
        $rb = $order[$b] ?? 1000;
        return $ra === $rb ? strcmp($a, $b) : $ra <=> $rb;
    });
    return $result;
}

/* ------------------------------------------------------------------
   RETRIEVING CHOSEN RECORDS
   - "not in the system" rows are added (INSERT)
   - "updated since the backup" rows are put back to the backup's
     version, only when that exact record was ticked (UPDATE by ID)
   Records that were not ticked are never touched.
------------------------------------------------------------------ */

/**
 * @param array<string, list<int>> $selection table => row indexes in the backup
 * @return array{success:bool, added:array<string,int>, restored:array<string,int>, skipped:array<string,int>, errors:list<string>, total_added:int, total_restored:int}
 */
function secsysMergeBackupIntoSystem(mysqli $conn, array $comparison, array $selection): array
{
    @set_time_limit(300);
    $added = [];
    $restored = [];
    $skipped = [];
    $errors = [];
    // Bookkeeping columns that describe the current login state, not the record
    $keepCurrent = ['is_logged_in', 'last_login', 'last_logout', 'last_seen', 'login_status'];

    $order = array_flip(SECSYS_MERGE_TABLE_ORDER);
    $tableNames = array_keys($comparison);
    usort($tableNames, function ($a, $b) use ($order) {
        return ($order[$a] ?? 1000) <=> ($order[$b] ?? 1000);
    });

    $fail = function (string $label, Throwable $e) use (&$errors) {
        if (count($errors) < 8) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    $conn->begin_transaction();
    try {
        foreach ($tableNames as $table) {
            $info = $comparison[$table];
            $chosen = array_values(array_unique(array_map('intval', (array)($selection[$table] ?? []))));
            if (!$chosen || (!$info['can_add'] && empty($info['can_restore']))) {
                continue;
            }
            $live = secsysMergeLiveTableInfo($conn, $table);
            if ($live === null) {
                continue;
            }
            $label = $info['label'] ?? $table;
            $added[$table] = 0;
            $restored[$table] = 0;
            $skipped[$table] = 0;

            foreach ($chosen as $i) {
                $row = $info['rows'][$i] ?? null;
                $status = $info['status'][$i] ?? '';
                if ($row === null) {
                    continue;
                }
                $cols = array_values(array_intersect(array_keys($row), $live['columns']));
                if (!$cols) {
                    continue;
                }

                if ($status === 'new' && $info['can_add']) {
                    $sql = 'INSERT INTO ' . secsysMergeQuoteName($table)
                         . ' (' . implode(', ', array_map('secsysMergeQuoteName', $cols)) . ')'
                         . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                    $values = [];
                    foreach ($cols as $c) {
                        $values[] = $row[$c];
                    }
                    try {
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                        $stmt->execute();
                        $stmt->close();
                        $added[$table]++;
                    } catch (mysqli_sql_exception $e) {
                        // e.g. it points to a record that is not in the system, or a duplicate plate
                        $skipped[$table]++;
                        $fail($label, $e);
                    }
                    continue;
                }

                if ($status === 'different' && !empty($info['can_restore'])) {
                    $pk = $info['primary'];
                    $liveId = $info['match_ids'][$i] ?? null;
                    $setCols = array_values(array_filter($cols, function ($c) use ($pk, $keepCurrent) {
                        return $c !== $pk && !in_array($c, $keepCurrent, true);
                    }));
                    if ($liveId === null || !$setCols) {
                        $skipped[$table]++;
                        continue;
                    }
                    $sql = 'UPDATE ' . secsysMergeQuoteName($table) . ' SET '
                         . implode(', ', array_map(function ($c) { return secsysMergeQuoteName($c) . ' = ?'; }, $setCols))
                         . ' WHERE ' . secsysMergeQuoteName($pk) . ' = ?';
                    $values = [];
                    foreach ($setCols as $c) {
                        $values[] = $row[$c];
                    }
                    $values[] = (string)$liveId;
                    try {
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                        $stmt->execute();
                        $stmt->close();
                        $restored[$table]++;
                    } catch (mysqli_sql_exception $e) {
                        $skipped[$table]++;
                        $fail($label, $e);
                    }
                }
                // "same" and "moved" records are already in the system: nothing to do
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'added' => [], 'restored' => [], 'skipped' => [], 'errors' => [$e->getMessage()],
                'total_added' => 0, 'total_restored' => 0];
    }

    return [
        'success'        => true,
        'added'          => $added,
        'restored'       => $restored,
        'skipped'        => $skipped,
        'errors'         => $errors,
        'total_added'    => array_sum($added),
        'total_restored' => array_sum($restored),
    ];
}
