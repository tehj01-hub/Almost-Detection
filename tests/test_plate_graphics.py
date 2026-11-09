"""Plate artwork must not be cropped into an extra digit."""
import unittest

import cv2
import numpy as np

from lpr_engine.quad_ensemble import QuadReaderEnsemble, extract_best_template_match


class PlateGraphicTests(unittest.TestCase):
    def test_misaligned_internal_graphic_keeps_full_plate_context(self):
        image = np.full((260, 540, 3), 255, np.uint8)
        for index in range(7):
            x = 30 + 70 * index
            top = 110 if index == 3 else 50
            cv2.rectangle(image, (x, top), (x + 20, 170), (0, 0, 0), -1)
        # Context outside the main glyph row can distinguish artwork from a digit.
        cv2.rectangle(image, (10, 10), (25, 25), (0, 0, 160), -1)
        result = QuadReaderEnsemble.number_region(image)
        self.assertTrue(np.array_equal(result, image))

    def test_real_digit_one_aligned_with_the_text_is_preserved(self):
        image = np.full((260, 540, 3), 255, np.uint8)
        for index in range(7):
            x = 30 + 70 * index
            width = 12 if index == 3 else 20
            color = (0, 0, 160) if index == 3 else (0, 0, 0)
            cv2.rectangle(image, (x, 50), (x + width, 170), color, -1)
        result = QuadReaderEnsemble.number_region(image)
        before = np.count_nonzero((image[:, :, 2] > 120) & (image[:, :, 0] < 30))
        after = np.count_nonzero((result[:, :, 2] > 120) & (result[:, :, 0] < 30))
        self.assertGreaterEqual(after, before * .8)

    def test_valid_seven_character_plate_is_not_shortened_by_a_format_rule(self):
        self.assertEqual(extract_best_template_match('SGG481', {})[0], 'SGG481')
        self.assertEqual(extract_best_template_match('SGG1481', {})[0], 'SGG1481')


if __name__ == '__main__':
    unittest.main()
