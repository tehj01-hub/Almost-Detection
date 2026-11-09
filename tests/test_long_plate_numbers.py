"""Long identifiers must retain their prefix, zeroes, and complete number line."""
import unittest

import cv2
import numpy as np

from lpr_engine.ctc_decoder import CandidatePrediction, DecodedPlateResult
from lpr_engine.ph_plate_validator import PhilippinePlateValidator
from lpr_engine.quad_ensemble import QuadReaderEnsemble, extract_best_template_match
from test_plate_formats import DisabledGPT, server_format_functions


class LongPlateNumberTests(unittest.TestCase):
    def test_full_eleven_digit_number_and_display_are_preserved(self):
        fallback = server_format_functions()['extract_exact_ph_plate_details']
        for raw in ('0401-0442987', '04010442987', '0401 0442987'):
            with self.subTest(raw=raw):
                plate, display, _, _, changes, _ = extract_best_template_match(raw, {})
                self.assertEqual((plate, display, changes), ('04010442987', '0401-0442987', 0))
                display, plate, _, _, changes, _ = fallback(raw, {})
                self.assertEqual((plate, display, changes), ('04010442987', '0401-0442987', 0))

    def test_numeric_identifiers_fit_the_database_without_short_window_extraction(self):
        fallback = server_format_functions()['extract_exact_ph_plate_details']
        for plate in ('012345678', '00010442987', '00012345678901234567'):
            with self.subTest(plate=plate):
                self.assertEqual(extract_best_template_match(plate, {})[0], plate)
                self.assertEqual(fallback(plate, {})[1], plate)

    def test_long_reading_reaches_accept_without_losing_the_prefix(self):
        reader = QuadReaderEnsemble(
            paddleocr_reader=object(), gpt_vision_reader=DisabledGPT(), min_accept_confidence=.65)
        reader.read_with_paddleocr = lambda crop: ('0401-0442987', .9999)
        crop = np.random.default_rng(7).integers(0, 255, (80, 280, 3), dtype=np.uint8)
        result = reader.recognize_ensemble(crop, {})
        self.assertEqual((result.decision, result.final_plate, result.display_plate),
                         ('ACCEPT', '04010442987', '0401-0442987'))

    def test_validator_accepts_the_complete_numeric_identifier(self):
        validator = PhilippinePlateValidator()
        decoded = DecodedPlateResult(raw_text='04010442987', plate_confidence=.99,
                                     top_candidates=[CandidatePrediction('04010442987', .99)])
        decision = validator.evaluate(decoded, frame_agreement_count=2)
        self.assertEqual((decision.action, decision.display_plate),
                         ('AUTO_ACCEPT', '0401-0442987'))

    def test_server_accepts_full_identifiers_and_rejects_oversized_numbers(self):
        validate = server_format_functions()['is_valid_plate']
        self.assertTrue(validate('0401-0442987'))
        self.assertTrue(validate('00012345678901234567'))
        self.assertFalse(validate('012345678901234567890'))

    def test_unstructured_image_is_not_blindly_cropped(self):
        image = np.full((260, 540, 3), 255, np.uint8)
        self.assertIs(QuadReaderEnsemble.number_region(image), image)

    def test_sloping_number_line_keeps_first_and_last_characters(self):
        image = np.full((260, 540, 3), 255, np.uint8)
        for index in range(7):
            x, y = 30 + index * 70, 10 + index * 12
            color = (0, 0, 160) if index == 0 else ((160, 0, 0) if index == 6 else (0, 0, 0))
            cv2.rectangle(image, (x, y), (x + 20, y + 60), color, -1)
        region = QuadReaderEnsemble.number_region(image)
        first_before = np.count_nonzero((image[:, :, 2] > 120) & (image[:, :, 0] < 30))
        first_after = np.count_nonzero((region[:, :, 2] > 120) & (region[:, :, 0] < 30))
        last_before = np.count_nonzero((image[:, :, 0] > 120) & (image[:, :, 2] < 30))
        last_after = np.count_nonzero((region[:, :, 0] > 120) & (region[:, :, 2] < 30))
        self.assertGreaterEqual(first_after, first_before * .8)
        self.assertGreaterEqual(last_after, last_before * .8)


if __name__ == '__main__':
    unittest.main()
