import sys
import os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import detect_plate_server as dps

test_cases = [
    ('00566CPJ', 'Registered conduction plate with screw noise'),
    ('0566CP', 'Registered conduction plate with 0 instead of D'),
    ('D566CP', 'Registered conduction plate exact'),
    ('D566CP1', 'Registered conduction plate with trailing screw 1'),
    ('N838R2', 'Registered conduction plate with 2 instead of Z'),
    ('1N838R2', 'Registered conduction plate with leading noise 1 and Z confused with 2'),
    ('NA16155', 'Registered motorcycle plate exact'),
    ('ABC1234', 'Visitor standard 4-wheel plate'),
    ('0ABC1234I', 'Visitor standard plate with screw noise'),
    ('XYZ9876', 'Visitor standard plate'),
    ('D567CP', 'Visitor conduction plate (not registered)'),
    ('ABC123', 'Visitor legacy plate (not registered)'),
]

header = f"{'RAW TEXT':<12} | {'EXTRACTED':<10} | {'COUNT':<5} | {'DISPLAY':<10} | {'CATEGORY':<10} | {'OFFICIAL':<10}"
print(header)
print("-" * len(header))

for raw, desc in test_cases:
    mock_ocr = [([[0,0],[100,0],[100,20],[0,20]], raw, 0.9)]
    disp, norm, conf = dps.clean_and_select_plate_text(mock_ocr)
    db_info = dps.check_plate_in_database(norm)
    cat = db_info['category']
    off = db_info.get('official_plate') or '-'
    print(f"{raw:<12} | {norm:<10} | {len(norm):<5} | {disp:<10} | {cat:<10} | {off:<10}")
