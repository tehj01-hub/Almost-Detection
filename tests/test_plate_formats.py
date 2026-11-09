"""Plate formats must classify OCR text without changing its characters."""
import ast
import itertools
from pathlib import Path
import re
import unittest

import numpy as np

from lpr_engine.ctc_decoder import CandidatePrediction, DecodedPlateResult
from lpr_engine.ph_plate_validator import PhilippinePlateValidator
from lpr_engine.quad_ensemble import (
    PH_TEMPLATES,
    QuadReaderEnsemble,
    extract_best_template_match,
    score_and_fix_syntax,
)


class DisabledGPT:
    def is_configured(self):
        return False


def server_format_functions():
    tree = ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8'))
    assignments = {'DIGIT_TO_LETTER', 'LETTER_TO_DIGIT', 'PATTERNS', 'NON_PLATE_WORDS'}
    functions = {'normalize_plate', 'is_valid_plate', 'score_and_fix', 'extract_exact_ph_plate_details',
                 'clean_and_select_plate_text', 'check_plate_in_database'}
    nodes = [node for node in tree.body if
             (isinstance(node, ast.Assign) and any(
                 isinstance(target, ast.Name) and target.id in assignments
                 for target in node.targets))
             or (isinstance(node, ast.FunctionDef) and node.name in functions)]
    namespace = {'re': re}
    exec(compile(ast.Module(body=nodes, type_ignores=[]), '<server-formats>', 'exec'), namespace)
    return namespace


class PlateFormatTests(unittest.TestCase):
    def test_zero_in_numeric_prefix_is_preserved(self):
        for raw, expected in [('9650DB', '9650DB'), ('9650 DB', '9650DB'),
                              ('0000DB', '0000DB'), ('0001AB', '0001AB')]:
            with self.subTest(raw=raw):
                plate, display, _, _, changes, _ = extract_best_template_match(raw, {})
                self.assertEqual(plate, expected)
                self.assertEqual(display.replace(' ', ''), expected)
                self.assertEqual(changes, 0)

    def test_all_five_to_seven_character_letter_digit_layouts_are_preserved(self):
        for length in (5, 6, 7):
            for layout in itertools.product((False, True), repeat=length):
                plate = ''.join(chr(ord('A') + i) if letter else str(i)
                                for i, letter in enumerate(layout))
                with self.subTest(plate=plate):
                    actual = extract_best_template_match(plate, {})
                    self.assertEqual(actual[0], plate)
                    self.assertEqual(actual[4], 0)

    def test_complete_reading_is_not_truncated_to_a_shorter_known_format(self):
        for plate in ('AB1234C', '1234ABC', 'A1B2C3D', '12ABC', 'ABCDEF'):
            with self.subTest(plate=plate):
                self.assertEqual(extract_best_template_match(plate, {})[0], plate)

    def test_header_words_inside_plate_numbers_do_not_remove_characters(self):
        extract_fallback = server_format_functions()['extract_exact_ph_plate_details']
        for plate in ('NCR123', 'LTO1234', 'METRO12', 'IND1234'):
            with self.subTest(plate=plate):
                self.assertEqual(extract_best_template_match(plate, {})[0], plate)
                self.assertEqual(extract_fallback(plate, {})[1], plate)

    def test_header_noise_does_not_remove_part_of_the_actual_plate(self):
        extract_fallback = server_format_functions()['extract_exact_ph_plate_details']
        cases = [('REGISTERED NCR123', 'NCR123'), ('NCR 123 REGISTERED', 'NCR123'),
                 ('REGISTERED LTO1234', 'LTO1234'), ('REGISTERED A1B2C3D', 'A1B2C3D')]
        for raw, expected in cases:
            with self.subTest(raw=raw):
                self.assertEqual(extract_best_template_match(raw, {})[0], expected)
                self.assertEqual(extract_fallback(raw, {})[1], expected)

    def test_format_mismatch_does_not_substitute_zero_with_d(self):
        motorcycle = next(tpl for tpl in PH_TEMPLATES if tpl[0] == 'motorcycle_alt')
        self.assertEqual(score_and_fix_syntax('9650DB', motorcycle), (None, 999, ''))

    def test_fallback_reader_preserves_ind_prefix_in_complete_plate(self):
        namespace = server_format_functions()
        namespace['get_cached_registered_plates'] = lambda: {}
        reading = [([[0, 0], [300, 0], [300, 80], [0, 80]], 'IND1234', .99)]
        self.assertEqual(namespace['clean_and_select_plate_text'](reading)[1], 'IND1234')

    def test_fallback_reader_preserves_spaced_letter_prefixes(self):
        namespace = server_format_functions()
        namespace['get_cached_registered_plates'] = lambda: {}
        for raw, expected in [('NCR 123', 'NCR123'), ('IND 1234', 'IND1234')]:
            with self.subTest(raw=raw):
                reading = [([[0, 0], [300, 0], [300, 80], [0, 80]], raw, .99)]
                self.assertEqual(namespace['clean_and_select_plate_text'](reading)[1], expected)

    def test_shorter_registration_does_not_override_complete_ocr_reading(self):
        namespace = server_format_functions()
        namespace['get_cached_registered_plates'] = lambda: {'AB1234': {}}
        readings = [([[0, 0], [240, 0], [240, 80], [0, 80]], 'AB1234', .99),
                    ([[250, 0], [300, 0], [300, 80], [250, 80]], 'C', .99)]
        self.assertEqual(namespace['clean_and_select_plate_text'](readings)[1], 'AB1234C')

    def test_fallback_preserves_unfamiliar_layout_next_to_header(self):
        namespace = server_format_functions()
        namespace['get_cached_registered_plates'] = lambda: {}
        for raw in ('AB1234C REGISTERED', 'REGISTERED AB1234C'):
            with self.subTest(raw=raw):
                reading = [([[0, 0], [300, 0], [300, 80], [0, 80]], raw, .99)]
                self.assertEqual(namespace['clean_and_select_plate_text'](reading)[1], 'AB1234C')

    def test_high_confidence_paddle_reading_reaches_accept_without_rewriting(self):
        ensemble = QuadReaderEnsemble(
            easyocr_reader=object(), paddleocr_reader=object(),
            gpt_vision_reader=DisabledGPT(), min_accept_confidence=.65)
        ensemble.read_with_easyocr = lambda crop: ('49650DB1', .13)
        ensemble.read_with_paddleocr = lambda crop: ('9650DB', .99988)
        crop = np.random.default_rng(7).integers(0, 255, (80, 280, 3), dtype=np.uint8)
        result = ensemble.recognize_ensemble(crop, {})
        self.assertEqual((result.decision, result.final_plate), ('ACCEPT', '9650DB'))
        self.assertEqual(result.model_predictions['paddleocr']['plate'], '9650DB')

    def test_generic_format_keeps_noise_rejection(self):
        for noise in ('REGISTERED', 'MANILA', 'AAAAAA', '000000', 'AB12', 'ABCDEFGH'):
            with self.subTest(noise=noise):
                self.assertEqual(extract_best_template_match(noise, {})[0], '')

    def test_validator_allows_new_layouts_without_changing_characters(self):
        validator = PhilippinePlateValidator()
        for plate in ('9650DB', '0000DB', 'AB1234C', 'A1B2C3D', '12ABC'):
            with self.subTest(plate=plate):
                decoded = DecodedPlateResult(
                    raw_text=plate, plate_confidence=.95,
                    top_candidates=[CandidatePrediction(plate, .95)])
                decision = validator.evaluate(decoded, frame_agreement_count=2)
                self.assertEqual((decision.action, decision.confirmed_plate),
                                 ('AUTO_ACCEPT', plate))

    def test_server_fallback_preserves_all_supported_layouts(self):
        extract = server_format_functions()['extract_exact_ph_plate_details']
        for plate in ('9650DB', '0000DB', 'AB1234C', 'A1B2C3D', '12ABC', '09420T'):
            with self.subTest(plate=plate):
                display, actual, _, _, changes, _ = extract(plate, {})
                self.assertEqual(actual, plate)
                self.assertEqual(display.replace(' ', ''), plate)
                self.assertEqual(changes, 0)


if __name__ == '__main__':
    unittest.main()
