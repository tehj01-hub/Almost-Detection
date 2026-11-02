# Fast plate recognition and photo capture

Keep the 60% detection/final confidence gate and existing OCR voting/tracker rules.
1. Test and replace redundant EasyOCR text detection with recognition of the known plate crop; retain raw-image retry.
2. Publish YOLO boxes before OCR completes; verify callback order with a fake model.
3. Save the exact confirmed reading crop plus JSON metadata after a successful database log. Use unique filenames and include paths in the detections API; a photo write failure must not duplicate a committed visit.
4. Run focused OCR, capture, lifecycle, tracker and safety tests; check live service health. Report measured performance limits rather than promise a frame rate.
