import sys

for pkg in ['easyocr', 'paddleocr', 'torch', 'cv2', 'mysql.connector']:
    try:
        m = __import__(pkg)
        v = getattr(m, '__version__', 'unknown')
        print(f"{pkg}: AVAILABLE (version: {v})")
    except Exception as e:
        print(f"{pkg}: NOT AVAILABLE ({e})")

