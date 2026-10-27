import cv2
from flask import Flask, Response

app = Flask(_name_)

RTSP_URL = "rtsp://Jhetlogs:12345678I@192.168.1.17:554/stream1"

camera = cv2.VideoCapture(RTSP_URL)


def generate_frames():

    while True:

        success, frame = camera.read()

        if not success:
            break

        # Convert frame to JPEG
        success, buffer = cv2.imencode(".jpg", frame)

        if not success:
            continue

        frame = buffer.tobytes()

        # Send frame to browser
        yield (
            b"--frame\r\n"
            b"Content-Type: image/jpeg\r\n\r\n"
            + frame
            + b"\r\n"
        )


@app.route("/")
def home():
    return """
    <h1>CCTV Server</h1>
    <p>Open cctv.html to view the camera.</p>
    """


@app.route("/video")
def video():
    return Response(
        generate_frames(),
        mimetype="multipart/x-mixed-replace; boundary=frame"
    )


if _name_ == "_main_":
    print("Starting CCTV server...")
    print("Open http://127.0.0.1:5000/video")
    app.run(host="0.0.0.0", port=5000, debug=False)