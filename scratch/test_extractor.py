import re
import time
import mysql.connector

DIGIT_TO_LETTER = {
    '0': 'D',
    '1': 'I',
    '2': 'Z',
    '4': 'A',
    '5': 'S',
    '6': 'G',
    '7': 'T',
    '8': 'B',
}

LETTER_TO_DIGIT = {
    'O': '0',
    'D': '0',
    'Q': '0',
    'I': '1',
    'L': '1',
    'T': '1',
    'Z': '2',
    'A': '4',
    'S': '5',
    'G': '6',
    'B': '8',
    'R': '8',
}

PATTERNS = [
    # (name, length, letter_indices, digit_indices, display_formatter)
    ('conduction', 6, [0, 4, 5], [1, 2, 3], lambda s: f"{s[0]} {s[1:4]} {s[4:6]}"),
    ('standard_4w', 7, [0, 1, 2], [3, 4, 5, 6], lambda s: f"{s[0:3]} {s[3:7]}"),
    ('legacy_4w', 6, [0, 1, 2], [3, 4, 5], lambda s: f"{s[0:3]} {s[3:6]}"),
    ('motorcycle_7', 7, [0, 1], [2, 3, 4, 5, 6], lambda s: f"{s[0:2]} {s[2:7]}"),
    ('motorcycle_6', 6, [0, 1], [2, 3, 4, 5], lambda s: f"{s[0:2]} {s[2:6]}"),
    ('motorcycle_alt', 6, [3, 4, 5], [0, 1, 2], lambda s: f"{s[0:3]} {s[3:6]}"),
]

NON_PLATE_WORDS = {
    'REGISTERED', 'PHILIPPINES', 'NCR', 'IND', 'MATATAG', 'REPUBLIKA',
    'REGION', 'DEALER', 'LTO', 'PILIPINAS', 'METRO', 'MANILA', 'CALABARZON',
    'CENTRAL', 'LUZON', 'VISAYAS', 'MINDANAO', 'FOR', 'REGISTRATION'
}

_REG_CACHE = {'timestamp': 0.0, 'data': {}}

def get_cached_registered_plates():
    global _REG_CACHE
    now = time.time()
    if now - _REG_CACHE['timestamp'] < 5.0 and _REG_CACHE['data']:
        return _REG_CACHE['data']
    try:
        conn = mysql.connector.connect(host='localhost', port=3307, user='root', password='', database='security_system')
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT id, plate_number, vehicle_type FROM registered_vehicles WHERE status = 'Active'")
        rows = cursor.fetchall()
        conn.close()
        reg_map = {}
        for r in rows:
            norm = re.sub(r'[^A-Z0-9]', '', r['plate_number'].upper())
            reg_map[norm] = r
        _REG_CACHE['timestamp'] = now
        _REG_CACHE['data'] = reg_map
        return reg_map
    except Exception as e:
        print("[DB-CACHE] Error:", e)
        return _REG_CACHE['data']

def score_and_fix(candidate, pattern_spec):
    name, length, let_indices, dig_indices, formatter = pattern_spec
    if len(candidate) != length:
        return None, 999

    chars = list(candidate)
    corrections = 0

    for idx in let_indices:
        c = chars[idx]
        if c.isdigit():
            if c in DIGIT_TO_LETTER:
                chars[idx] = DIGIT_TO_LETTER[c]
                corrections += 1
            else:
                return None, 999
        elif not c.isalpha():
            return None, 999

    for idx in dig_indices:
        c = chars[idx]
        if c.isalpha():
            if c in LETTER_TO_DIGIT:
                chars[idx] = LETTER_TO_DIGIT[c]
                corrections += 1
            else:
                return None, 999
        elif not c.isdigit():
            return None, 999

    result = ''.join(chars)
    return result, corrections

def extract_exact_ph_plate_details(raw_string, registered_plates=None):
    if not raw_string:
        return "", "", 0.0, "", 999, False

    if registered_plates is None:
        registered_plates = get_cached_registered_plates()

    cleaned = re.sub(r'[^A-Z0-9]', '', raw_string.upper())
    for word in NON_PLATE_WORDS:
        cleaned = cleaned.replace(word, '')

    if len(cleaned) < 4:
        return "", "", 0.0, "", 999, False

    candidates = []

    # Slide windows of length 6 and 7 across the cleaned string
    for L in (6, 7):
        for start in range(len(cleaned) - L + 1):
            sub = cleaned[start:start + L]
            for pat in PATTERNS:
                res, cost = score_and_fix(sub, pat)
                if res:
                    is_reg = res in registered_plates
                    priority = cost - (100 if is_reg else 0)
                    formatter = pat[4]
                    disp = formatter(res)
                    candidates.append((priority, cost, -len(sub), res, disp, pat[0], is_reg))

    if candidates:
        candidates.sort(key=lambda x: (x[0], x[1], x[2]))
        best = candidates[0]
        # (priority, cost, -len, norm, disp, pat_name, is_reg)
        conf = max(0.5, 1.0 - (best[1] * 0.15))
        return best[4], best[3], conf, best[5], best[1], best[6]

    return "", "", 0.0, "", 999, False

def clean_and_select_plate_text(ocr_results):
    if not ocr_results:
        return "", "", 0.0

    blocks = []
    all_raw_tokens = []
    for item in ocr_results:
        if len(item) < 3:
            continue
        bbox, raw_text, conf = item[0], str(item[1]).strip(), float(item[2])
        if not raw_text:
            continue

        try:
            ys = [pt[1] for pt in bbox]
            xs = [pt[0] for pt in bbox]
            height = max(ys) - min(ys)
            min_x = min(xs)
            min_y = min(ys)
        except Exception:
            height = 20.0
            min_x = 0.0
            min_y = 0.0

        tokens = re.split(r'\s+', raw_text.upper())
        filtered = []
        for tok in tokens:
            clean_tok = re.sub(r'[^A-Z0-9]', '', tok)
            if clean_tok and clean_tok not in NON_PLATE_WORDS:
                if len(clean_tok) > 6 and clean_tok.startswith('IND'):
                    clean_tok = clean_tok[3:]
                filtered.append(clean_tok)
                all_raw_tokens.append(clean_tok)

        if filtered:
            line_str = " ".join(filtered)
            blocks.append({
                'text': line_str,
                'tokens': filtered,
                'conf': conf,
                'height': height,
                'min_x': min_x,
                'min_y': min_y
            })

    if not blocks:
        return "", "", 0.0

    blocks_sorted_x = sorted(blocks, key=lambda b: b['min_x'])
    combined_horizontal = "".join("".join(b['tokens']) for b in blocks_sorted_x)

    blocks_sorted_y = sorted(blocks, key=lambda b: b['min_y'])
    combined_vertical = "".join("".join(b['tokens']) for b in blocks_sorted_y)

    candidate_strings = [
        combined_horizontal,
        combined_vertical,
        "".join(all_raw_tokens)
    ] + all_raw_tokens

    seen_strings = set()
    unique_candidates = []
    for s in candidate_strings:
        c_clean = re.sub(r'[^A-Z0-9]', '', s.upper())
        if c_clean and c_clean not in seen_strings:
            seen_strings.add(c_clean)
            unique_candidates.append(c_clean)

    reg_cache = get_cached_registered_plates()

    best_result = None
    best_priority = 9999

    for cand_str in unique_candidates:
        disp, norm, conf, pat_name, cost, is_reg = extract_exact_ph_plate_details(cand_str, reg_cache)
        if norm:
            priority = cost - (100 if is_reg else 0)
            if priority < best_priority:
                best_priority = priority
                best_result = (disp, norm, conf)
                if is_reg and cost == 0:
                    break

    if best_result:
        return best_result

    for cand_str in unique_candidates:
        if 5 <= len(cand_str) <= 7 and cand_str not in NON_PLATE_WORDS and len(set(cand_str)) > 1:
            return cand_str, cand_str, 0.5

    return "", "", 0.0

# Mock test representing typical EasyOCR outputs
sample_ocr_scenarios = [
    # 1. Single crop with screw noise
    ([
        ([[0,0],[100,0],[100,20],[0,20]], "00566CPJ", 0.91)
    ], "Car D566CP with screws"),

    # 2. Split crop into 2 blocks: ['D566', 'CP']
    ([
        ([[0,0],[50,0],[50,20],[0,20]], "D566", 0.89),
        ([[55,0],[100,0],[100,20],[55,20]], "CP", 0.94)
    ], "Car D566CP split horizontally"),

    # 3. Z confused with 2: N838R2
    ([
        ([[0,0],[100,0],[100,20],[0,20]], "N838R2", 0.87)
    ], "Car N838RZ Z confused with 2"),

    # 4. Motorcycle 2-row plate: ['NA', '16155']
    ([
        ([[0,0],[100,0],[100,20],[0,20]], "NA", 0.95),
        ([[0,25],[100,25],[100,45],[0,45]], "16155", 0.93)
    ], "Motorcycle NA16155 2 rows"),

    # 5. Visitor car 7 chars
    ([
        ([[0,0],[100,0],[100,20],[0,20]], "XYZ9876", 0.88)
    ], "Visitor XYZ9876"),

    # 6. Visitor car with screw noise
    ([
        ([[0,0],[100,0],[100,20],[0,20]], "0XYZ9876I", 0.85)
    ], "Visitor XYZ9876 with 0...I noise"),
]

for ocr_res, desc in sample_ocr_scenarios:
    disp, norm, conf = clean_and_select_plate_text(ocr_res)
    print(f"Scenario: {desc}")
    print(f"  -> Display: '{disp}' | Norm: '{norm}' (Len: {len(norm)}) | Conf: {conf:.2f}")

