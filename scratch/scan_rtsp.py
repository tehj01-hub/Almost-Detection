import socket

def scan():
    found = []
    for i in range(1, 100):
        ip = f"192.168.1.{i}"
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(0.15)
        res = s.connect_ex((ip, 554))
        if res == 0:
            found.append(ip)
            print(f"FOUND RTSP PORT 554 AT: {ip}")
        s.close()
    return found

if __name__ == '__main__':
    scan()

