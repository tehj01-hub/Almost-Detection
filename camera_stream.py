import os
import time
import cv2
from flask import Flask, Response, render_template_string

# Force OpenCV to use TCP for RTSP (prevents corrupted/gray frames on Tapo cameras)
os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp"

app = Flask(__name__)

# --- TAPO C500 CONFIGURATION ---
TAPO_USER = "Jhetlogs"      # From Tapo App -> Advanced Settings -> Camera Account
TAPO_PASS = "123456789ten"      # From Tapo App -> Advanced Settings -> Camera Account
TAPO_IP   = "192.168.1.25"            # Replace with your Tapo C500's actual IP address
STREAM_PATH = "stream1"                 # Use "stream1" for 1080p HD, or "stream2" for 360p smooth stream

RTSP_URL = f"rtsp://Jhetlogs:123456789ten@192.168.1.25:554/stream1"

def generate_frames():
    camera = cv2.VideoCapture(RTSP_URL, cv2.CAP_FFMPEG)
    # Reduce internal buffer size so the video feed stays real-time without delay
    camera.set(cv2.CAP_PROP_BUFFERSIZE, 1)

    if not camera.isOpened():
        print(f"Error: Unable to connect to Tapo C500 at {RTSP_URL}")
        return

    try:
        while True:
            success, frame = camera.read()
            if not success:
                # If the Wi-Fi drops briefly, reconnect automatically
                print("Lost frame from Tapo C500. Reconnecting...")
                camera.release()
                time.sleep(2)
                camera = cv2.VideoCapture(RTSP_URL, cv2.CAP_FFMPEG)
                continue

            # Encode the frame as JPEG (80% quality balances clarity and speed)
            ret, buffer = cv2.imencode('.jpg', frame, [int(cv2.IMWRITE_JPEG_QUALITY), 80])
            if not ret:
                continue

            frame_bytes = buffer.tobytes()

            yield (b'--frame\r\n'
                   b'Content-Type: image/jpeg\r\n\r\n' + frame_bytes + b'\r\n')
    finally:
        camera.release()

@app.route('/')
def index():
    html = '''
    <html>
        <head><title>Tapo C500 Live Stream</title></head>
        <body style="background: #111; color: #eee; text-align: center; font-family: sans-serif;">
            <h2>Tapo C500 Live Feed</h2>
            <img src="/video_feed" style="border: 2px solid #444; max-width: 90%; height: auto;" />
        </body>
    </html>
    '''
    return render_template_string(html)

@app.route('/video_feed')
def video_feed():
    return Response(generate_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')

if __name__ == '__main__':
    # Runs the web server on your machine (http://192.168.100.50:5000/video_feed)
    app.run(host='0.0.0.0', port=5000, debug=False, threaded=True)
