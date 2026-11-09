<?php
/* =========================================================
   PLATE DETAILED REPORT (Report > Plate Report)
   ---------------------------------------------------------
   Everything the system knows about ONE plate number, for the
   report's period or for all dates:
     - vehicle details (registered owner + extra owner fields,
       or visitor details)
     - summary (entries, exits, visits, time inside, first /
       last seen, inside now)
     - every IN / OUT movement (active and archived logs)
     - every visit (time in / time out / duration)
     - exit-without-entry warnings
     - audit trail entries that mention the plate
   Plates are matched by letters and digits only, so
   "ABC 1234", "ABC-1234" and "abc1234" are the same plate.
========================================================= */

require_once __DIR__ . '/system_config.php';
require_once __DIR__ . '/print_report.php';
require_once __DIR__ . '/owner_fields.php';

/** "ABC-1234 " -> "ABC1234" */
function secsysPlateReportNorm(string $plate): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($plate));
}

/** SQL for a column's plate with only letters and digits (MariaDB REGEXP_REPLACE). */
function secsysPlateReportNormSql(string $column): string
{
    return "REGEXP_REPLACE(UPPER($column), '[^A-Z0-9]', '')";
}

function secsysPlateReportFetchAll(mysqli $conn, string $sql, string $types, array $params): array
{
    $rows = [];
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        return $rows;
    }
    return $rows;
}

/** "2 h 05 m" from seconds. */
function secsysPlateReportDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 m';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    $out = [];
    if ($days > 0) {
        $out[] = $days . ' d';
    }
    if ($hours > 0 || $days > 0) {
        $out[] = $hours . ' h';
    }
    $out[] = sprintf('%02d m', $mins);
    return implode(' ', $out);
}

function secsysPlateReportTime($value): string
{
    $ts = $value ? strtotime((string)$value) : false;
    return $ts ? date('M d, Y h:i A', $ts) : '-';
}

/**
 * Collect the plate's data.
 * $start / $end: 'Y-m-d' for one period, or null for all dates.
 */
function secsysPlateReportData(mysqli $conn, string $plate, ?string $start, ?string $end): array
{
    $norm = secsysPlateReportNorm($plate);
    $hasRange = $start !== null && $end !== null;

    $range = function (string $column) use ($hasRange): string {
        return $hasRange ? " AND DATE($column) BETWEEN ? AND ?" : '';
    };
    $params = function (array $base) use ($hasRange, $start, $end): array {
        return $hasRange ? array_merge($base, [$start, $end]) : $base;
    };
    $types = function (string $base) use ($hasRange): string {
        return $hasRange ? $base . 'ss' : $base;
    };

    // Vehicle details
    $registered = secsysPlateReportFetchAll($conn,
        "SELECT * FROM registered_vehicles WHERE " . secsysPlateReportNormSql('plate_number') . " = ? ORDER BY id DESC LIMIT 1",
        's', [$norm]);
    $registeredArchived = $registered ? [] : secsysPlateReportFetchAll($conn,
        "SELECT * FROM registered_vehicles_archive WHERE " . secsysPlateReportNormSql('plate_number') . " = ? ORDER BY archived_at DESC LIMIT 1",
        's', [$norm]);
    $visitor = secsysPlateReportFetchAll($conn,
        "SELECT * FROM visitor_vehicles WHERE " . secsysPlateReportNormSql('plate_number') . " = ? ORDER BY id DESC LIMIT 1",
        's', [$norm]);

    // Every IN / OUT movement: active logs + archived logs
    $movements = secsysPlateReportFetchAll($conn,
        "SELECT id, plate_number, vehicle_category, vehicle_type, direction, purpose, recognition_method, visitor_name,
                date_created, NULL AS archived_at, NULL AS archived_by, 'Active' AS log_status
         FROM vehicle_logs
         WHERE " . secsysPlateReportNormSql('plate_number') . " = ?" . $range('date_created') . "
         UNION ALL
         SELECT id, plate_number, vehicle_category, vehicle_type, direction, purpose, recognition_method, visitor_name,
                date_created, archived_at, archived_by, 'Archived' AS log_status
         FROM vehicle_logs_archive
         WHERE " . secsysPlateReportNormSql('plate_number') . " = ?" . $range('date_created') . "
         ORDER BY date_created ASC, id ASC",
        $types('s') . ($hasRange ? 'sss' : 's'),
        array_merge($params([$norm]), $params([$norm])));

    // Visits (time in / time out)
    $visits = secsysPlateReportFetchAll($conn,
        "SELECT id, plate_number, vehicle_category, vehicle_type, purpose, allowed_duration_label, time_in, time_out, status
         FROM vehicle_log_dates
         WHERE " . secsysPlateReportNormSql('plate_number') . " = ?" . $range('COALESCE(time_in, time_out, date_created)') . "
         ORDER BY COALESCE(time_in, time_out, date_created) ASC, id ASC",
        $types('s'), $params([$norm]));

    // Exit-without-entry warnings
    $alerts = secsysPlateReportFetchAll($conn,
        "SELECT * FROM exit_without_entry_alerts WHERE normalized_plate = ?" . $range('detected_at') . " ORDER BY detected_at ASC",
        $types('s'), $params([$norm]));

    // Audit entries that mention the plate (as typed or without spaces)
    $audit = [];
    $plateTyped = strtoupper(trim($plate));
    foreach (secsysPlateReportFetchAll($conn,
        "SELECT guard_id, activity_type, activity_description, date_created FROM guard_activity
         WHERE (UPPER(activity_description) LIKE ? OR REGEXP_REPLACE(UPPER(activity_description), '[^A-Z0-9]', '') LIKE ?)"
         . $range('date_created') . " ORDER BY date_created ASC",
        $types('ss'), $params(['%' . $plateTyped . '%', '%' . $norm . '%'])) as $row) {
        $audit[] = $row;
    }

    // Summary
    $ins = 0;
    $outs = 0;
    $firstSeen = null;
    $lastSeen = null;
    foreach ($movements as $m) {
        if (strtoupper((string)$m['direction']) === 'OUT') {
            $outs++;
        } else {
            $ins++;
        }
        $t = (string)$m['date_created'];
        if ($firstSeen === null || $t < $firstSeen) {
            $firstSeen = $t;
        }
        if ($lastSeen === null || $t > $lastSeen) {
            $lastSeen = $t;
        }
    }
    $timeInside = 0;
    foreach ($visits as $v) {
        if (!empty($v['time_in']) && !empty($v['time_out'])) {
            $timeInside += max(0, strtotime((string)$v['time_out']) - strtotime((string)$v['time_in']));
        }
    }
    $insideNow = !empty(secsysPlateReportFetchAll($conn,
        "SELECT id FROM vehicle_log_dates WHERE " . secsysPlateReportNormSql('plate_number') . " = ? AND status = 'IN' AND time_out IS NULL LIMIT 1",
        's', [$norm]));

    $displayPlate = $registered[0]['plate_number'] ?? $registeredArchived[0]['plate_number']
        ?? $visitor[0]['plate_number'] ?? ($movements[0]['plate_number'] ?? strtoupper(trim($plate)));

    return [
        'plate'               => $displayPlate,
        'normalized'          => $norm,
        'registered'          => $registered[0] ?? null,
        'registered_archived' => $registeredArchived[0] ?? null,
        'visitor'             => $visitor[0] ?? null,
        'movements'           => $movements,
        'visits'              => $visits,
        'alerts'              => $alerts,
        'audit'               => $audit,
        'summary'             => [
            'ins'         => $ins,
            'outs'        => $outs,
            'visits'      => count($visits),
            'time_inside' => $timeInside,
            'first_seen'  => $firstSeen,
            'last_seen'   => $lastSeen,
            'inside_now'  => $insideNow,
            'alerts'      => count($alerts),
        ],
    ];
}

/** True when the system has any record at all for this plate. */
function secsysPlateReportHasData(array $d): bool
{
    return $d['registered'] || $d['registered_archived'] || $d['visitor']
        || $d['movements'] || $d['visits'] || $d['alerts'];
}

/** Body of the printable / PDF report. */
function secsysPlateReportBodyHtml(mysqli $conn, array $d): string
{
    $guardNames = [];
    foreach (secsysPlateReportFetchAll($conn, "SELECT guard_id, full_name FROM security_guard", '', []) as $g) {
        $guardNames[(string)$g['guard_id']] = (string)$g['full_name'];
    }
    $actor = function ($id) use ($guardNames): string {
        $id = trim((string)$id);
        if ($id === '') {
            return '-';
        }
        if (strtoupper($id) === 'SYSTEM') {
            return 'System (Automatic)';
        }
        return isset($guardNames[$id]) ? $guardNames[$id] . ' (' . $id . ')' : $id;
    };
    $c = function ($text): array {
        return ['text' => (string)$text, 'class' => 'center'];
    };

    $html = '';

    // 1. Vehicle details
    $details = [];
    $reg = $d['registered'] ?: $d['registered_archived'];
    if ($reg) {
        $details[] = ['Category', 'Registered' . ($d['registered'] ? '' : ' (archived on ' . secsysPlateReportTime($reg['archived_at'] ?? null) . ')')];
        $details[] = ['Vehicle', trim(($reg['brand'] ?? '') . ' ' . ($reg['model'] ?? ''))];
        $details[] = ['Vehicle Type', (string)($reg['vehicle_type'] ?? '')];
        $details[] = ['Address', (string)($reg['address'] ?? '')];
        $extra = secsysOwnerExtraDecode($reg['extra_info'] ?? null);
        foreach (secsysOwnerFields($conn) as $f) {
            $details[] = [$f['label'], ($extra[$f['key']] ?? '') !== '' ? $extra[$f['key']] : '-'];
        }
        $details[] = ['Registered Since', secsysPlateReportTime($reg['date_created'] ?? ($reg['original_date_created'] ?? null))];
    } elseif ($d['visitor']) {
        $v = $d['visitor'];
        $name = trim((string)($v['visitor_name'] ?? ''));
        $details[] = ['Category', 'Visitor'];
        $details[] = ['Visitor Name', ($name === '' || strcasecmp($name, 'Visitor') === 0) ? '-' : $name];
        $details[] = ['Vehicle Type', (string)($v['vehicle_type'] ?? '')];
        $details[] = ['Address to Visit', ((string)($v['address_to_visit'] ?? '') !== '' && $v['address_to_visit'] !== '-') ? $v['address_to_visit'] : '-'];
        $details[] = ['ID Presented', trim((string)($v['id_type'] ?? '')) !== '' ? trim($v['id_type'] . ' ' . ($v['id_number'] ?? '')) : '-'];
        $details[] = ['Latest Purpose', (string)($v['purpose'] ?? '') !== '' ? $v['purpose'] : '-'];
    } else {
        $details[] = ['Category', 'Not registered (no visitor record either)'];
    }
    $s = $d['summary'];
    $html .= secsysPrintTable([['Detail', 30], ['Value', 70]], array_map(function ($r) { return [$r[0], $r[1]]; }, $details),
        'No details.', '1. Vehicle Details — ' . $d['plate']);

    // 2. Summary
    $html .= secsysPrintTable(
        ['Entries (IN)', 'Exits (OUT)', 'Visits', 'Total Time Inside', 'First Seen', 'Last Seen', 'Inside Now?', 'Exit Without Entry'],
        [[$c($s['ins']), $c($s['outs']), $c($s['visits']), $c(secsysPlateReportDuration((int)$s['time_inside'])),
          $c(secsysPlateReportTime($s['first_seen'])), $c(secsysPlateReportTime($s['last_seen'])),
          $c($s['inside_now'] ? 'Yes (Inside)' : 'No (Outside)'), $c($s['alerts'])]],
        'No data.', '2. Summary');

    // 3. Movements
    $rows = [];
    foreach ($d['movements'] as $i => $m) {
        $dir = strtoupper((string)$m['direction']) === 'OUT' ? 'OUT' : 'IN';
        $rows[] = [
            $c($i + 1),
            secsysPlateReportTime($m['date_created']),
            $c($dir),
            (string)($m['vehicle_category'] ?? ''),
            (string)($m['vehicle_type'] ?? ''),
            (string)($m['purpose'] ?? '') !== '' ? $m['purpose'] : '-',
            $c((string)($m['recognition_method'] ?? '') !== '' ? $m['recognition_method'] : '-'),
            $m['log_status'] === 'Archived'
                ? 'Archived ' . secsysPlateReportTime($m['archived_at']) . ' by ' . $actor($m['archived_by'])
                : 'Active (not yet archived)',
        ];
    }
    $html .= secsysPrintTable(
        [['#', 5], ['Date & Time', 17], ['Direction', 8], ['Category', 10], ['Vehicle Type', 10], ['Purpose', 18], ['Read By', 8], ['Log Status', 24]],
        $rows, 'No IN / OUT movements in this range.', '3. Entry & Exit Movements (' . count($rows) . ')');

    // 4. Visits
    $rows = [];
    foreach ($d['visits'] as $i => $v) {
        $dur = (!empty($v['time_in']) && !empty($v['time_out']))
            ? secsysPlateReportDuration(max(0, strtotime((string)$v['time_out']) - strtotime((string)$v['time_in'])))
            : (empty($v['time_out']) ? 'Still inside' : '-');
        $rows[] = [
            $c($i + 1),
            secsysPlateReportTime($v['time_in']),
            secsysPlateReportTime($v['time_out']),
            $c($dur),
            (string)($v['purpose'] ?? '') !== '' ? $v['purpose'] : '-',
            (string)($v['allowed_duration_label'] ?? '') !== '' ? $v['allowed_duration_label'] : '-',
            $c((string)($v['status'] ?? '')),
        ];
    }
    $html .= secsysPrintTable(
        [['#', 5], ['Time In', 18], ['Time Out', 18], ['Duration', 12], ['Purpose', 22], ['Allowed Time', 13], ['Status', 8]],
        $rows, 'No visits in this range.', '4. Visits (' . count($rows) . ')');

    // 5. Exit without entry
    $rows = [];
    foreach ($d['alerts'] as $i => $a) {
        $rows[] = [
            $c($i + 1),
            secsysPlateReportTime($a['detected_at']),
            (string)($a['camera_name'] ?: 'Exit Cam'),
            $c(($a['status'] ?? 'New') === 'Acknowledged' ? 'Acknowledged' : 'Not Acknowledged'),
            ($a['status'] ?? '') === 'Acknowledged' ? $actor($a['acknowledged_by']) . ' — ' . secsysPlateReportTime($a['acknowledged_at']) : '-',
            (string)($a['ack_note'] ?? '') !== '' ? $a['ack_note'] : '-',
        ];
    }
    $html .= secsysPrintTable(
        [['#', 5], ['Detected', 18], ['Camera', 14], ['Status', 14], ['Acknowledged By', 25], ["Guard's Note", 24]],
        $rows, 'No exit-without-entry warnings in this range.', '5. Exit Without Entry Warnings (' . count($rows) . ')');

    // 6. Audit trail
    $rows = [];
    foreach ($d['audit'] as $i => $a) {
        $rows[] = [
            $c($i + 1),
            secsysPlateReportTime($a['date_created']),
            $actor($a['guard_id']),
            (string)$a['activity_type'],
            (string)$a['activity_description'],
        ];
    }
    $html .= secsysPrintTable(
        [['#', 5], ['Date & Time', 16], ['Done By', 18], ['Action', 16], ['Description', 45]],
        $rows, 'No audit trail entries mention this plate in this range.', '6. Audit Trail Mentions (' . count($rows) . ')');

    return $html;
}

/** Distinct plates the system knows (for the Plate Report picker). */
function secsysPlateReportKnownPlates(mysqli $conn, int $limit = 600): array
{
    $rows = secsysPlateReportFetchAll($conn, "
        SELECT plate_number, MAX(seen) AS seen FROM (
            SELECT plate_number, date_created AS seen FROM vehicle_logs
            UNION ALL SELECT plate_number, date_created FROM vehicle_logs_archive
            UNION ALL SELECT plate_number, date_created FROM registered_vehicles
            UNION ALL SELECT plate_number, date_created FROM visitor_vehicles
        ) p
        WHERE plate_number IS NOT NULL AND plate_number <> ''
        GROUP BY " . secsysPlateReportNormSql('plate_number') . "
        ORDER BY seen DESC
        LIMIT " . (int)$limit, '', []);
    return array_values(array_unique(array_map(function ($r) { return (string)$r['plate_number']; }, $rows)));
}
