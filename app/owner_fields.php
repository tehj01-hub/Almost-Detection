<?php
/* =========================================================
   CUSTOM OWNER FIELDS (Registered page)
   ---------------------------------------------------------
   The Administrator can add extra information to registered
   owners (e.g. "Owner Name", "Contact Number") without any
   code change: click "Add Field" in the Add New Owner form.

   - The list of fields is a JSON setting:
       system_settings.registered_owner_fields
       [{"key": "f_ab12cd", "label": "Owner Name"}, ...]
   - Each vehicle's values are a JSON object in
       registered_vehicles.extra_info  {"f_ab12cd": "Juan Dela Cruz"}
     (copied to registered_vehicles_archive when archived).
   - Removing a field only hides it; saved values are kept, so
     adding a field with the same name again brings them back.
========================================================= */

require_once __DIR__ . '/system_config.php';

const SECSYS_OWNER_FIELDS_SETTING = 'registered_owner_fields';
const SECSYS_OWNER_FIELD_MAX_LABEL = 40;
const SECSYS_OWNER_FIELD_MAX_VALUE = 255;
const SECSYS_OWNER_FIELD_MAX_COUNT = 12;

/** Active custom fields, in display order: list of ['key' => ..., 'label' => ...]. */
function secsysOwnerFields(mysqli $conn): array
{
    $raw = json_decode(secsysGetSetting($conn, SECSYS_OWNER_FIELDS_SETTING, '[]'), true);
    $fields = [];
    foreach (is_array($raw) ? $raw : [] as $f) {
        $key = (string)($f['key'] ?? '');
        $label = trim((string)($f['label'] ?? ''));
        if (preg_match('/^f_[a-z0-9]{4,16}$/', $key) && $label !== '' && empty($f['removed'])) {
            $fields[] = ['key' => $key, 'label' => $label];
        }
    }
    return $fields;
}

/** Every field ever defined (including removed ones), keyed by key. */
function secsysOwnerFieldsAll(mysqli $conn): array
{
    $raw = json_decode(secsysGetSetting($conn, SECSYS_OWNER_FIELDS_SETTING, '[]'), true);
    $all = [];
    foreach (is_array($raw) ? $raw : [] as $f) {
        $key = (string)($f['key'] ?? '');
        if (preg_match('/^f_[a-z0-9]{4,16}$/', $key)) {
            $all[$key] = [
                'key'     => $key,
                'label'   => trim((string)($f['label'] ?? '')),
                'removed' => !empty($f['removed']),
            ];
        }
    }
    return $all;
}

function secsysOwnerFieldsSave(mysqli $conn, array $all): bool
{
    return secsysSetSetting($conn, SECSYS_OWNER_FIELDS_SETTING, json_encode(array_values($all), JSON_UNESCAPED_UNICODE));
}

function secsysOwnerFieldCleanLabel(string $label): string
{
    $label = trim(preg_replace('/\s+/', ' ', strip_tags($label)));
    return mb_substr($label, 0, SECSYS_OWNER_FIELD_MAX_LABEL);
}

/**
 * Add a field (or bring back a removed one with the same name).
 * @return array{ok:bool, message:string, field?:array}
 */
function secsysOwnerFieldAdd(mysqli $conn, string $label): array
{
    $label = secsysOwnerFieldCleanLabel($label);
    if ($label === '') {
        return ['ok' => false, 'message' => 'Please enter a name for the new field.'];
    }
    $builtIn = ['brand', 'plate number', 'plate', 'model', 'vehicle type', 'type', 'address', 'vehicle'];
    if (in_array(strtolower($label), $builtIn, true)) {
        return ['ok' => false, 'message' => '"' . $label . '" is already part of the form.'];
    }
    $all = secsysOwnerFieldsAll($conn);
    foreach ($all as $key => $f) {
        if (strcasecmp($f['label'], $label) === 0) {
            if (!$f['removed']) {
                return ['ok' => false, 'message' => 'There is already a field called "' . $label . '".'];
            }
            $all[$key]['removed'] = false; // bring back, with its saved values
            secsysOwnerFieldsSave($conn, $all);
            return ['ok' => true, 'message' => 'Field "' . $label . '" added back.', 'field' => ['key' => $key, 'label' => $f['label']]];
        }
    }
    $active = array_filter($all, function ($f) { return !$f['removed']; });
    if (count($active) >= SECSYS_OWNER_FIELD_MAX_COUNT) {
        return ['ok' => false, 'message' => 'You can have up to ' . SECSYS_OWNER_FIELD_MAX_COUNT . ' extra fields.'];
    }
    do {
        $key = 'f_' . bin2hex(random_bytes(4));
    } while (isset($all[$key]));
    $all[$key] = ['key' => $key, 'label' => $label, 'removed' => false];
    secsysOwnerFieldsSave($conn, $all);
    return ['ok' => true, 'message' => 'Field "' . $label . '" added.', 'field' => ['key' => $key, 'label' => $label]];
}

function secsysOwnerFieldRename(mysqli $conn, string $key, string $label): array
{
    $label = secsysOwnerFieldCleanLabel($label);
    $all = secsysOwnerFieldsAll($conn);
    if (!isset($all[$key]) || $all[$key]['removed']) {
        return ['ok' => false, 'message' => 'That field no longer exists.'];
    }
    if ($label === '') {
        return ['ok' => false, 'message' => 'Please enter a name for the field.'];
    }
    foreach ($all as $k => $f) {
        if ($k !== $key && !$f['removed'] && strcasecmp($f['label'], $label) === 0) {
            return ['ok' => false, 'message' => 'There is already a field called "' . $label . '".'];
        }
    }
    $old = $all[$key]['label'];
    $all[$key]['label'] = $label;
    secsysOwnerFieldsSave($conn, $all);
    return ['ok' => true, 'message' => 'Renamed "' . $old . '" to "' . $label . '".', 'old' => $old];
}

function secsysOwnerFieldRemove(mysqli $conn, string $key): array
{
    $all = secsysOwnerFieldsAll($conn);
    if (!isset($all[$key]) || $all[$key]['removed']) {
        return ['ok' => false, 'message' => 'That field no longer exists.'];
    }
    $all[$key]['removed'] = true;
    secsysOwnerFieldsSave($conn, $all);
    return ['ok' => true, 'message' => 'Field "' . $all[$key]['label'] . '" removed.', 'label' => $all[$key]['label']];
}

/** Decode a vehicle's extra_info JSON into [key => value]. */
function secsysOwnerExtraDecode($json): array
{
    $data = json_decode((string)$json, true);
    $out = [];
    foreach (is_array($data) ? $data : [] as $k => $v) {
        if (is_string($k) && (is_string($v) || is_numeric($v))) {
            $out[$k] = (string)$v;
        }
    }
    return $out;
}

/**
 * Values posted from the form (name="extra[f_xxxx]") for the active fields,
 * merged into the vehicle's existing values (removed fields keep theirs).
 */
function secsysOwnerExtraFromPost(array $fields, $posted, array $existing = []): array
{
    $posted = is_array($posted) ? $posted : [];
    foreach ($fields as $f) {
        if (array_key_exists($f['key'], $posted)) {
            $value = trim(preg_replace('/\s+/', ' ', (string)$posted[$f['key']]));
            $value = mb_substr($value, 0, SECSYS_OWNER_FIELD_MAX_VALUE);
            if ($value === '') {
                unset($existing[$f['key']]);
            } else {
                $existing[$f['key']] = $value;
            }
        }
    }
    return $existing;
}

function secsysOwnerExtraEncode(array $extra): ?string
{
    return $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;
}

/** An owner's (address group's) value for a field: the first vehicle that has one. */
function secsysOwnerGroupValue(array $vehicles, string $key): string
{
    foreach ($vehicles as $v) {
        $value = (string)($v['extra'][$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}
