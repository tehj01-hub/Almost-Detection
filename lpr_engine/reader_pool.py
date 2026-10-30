"""Bounded OCR workers with a deadline shared by all readers for one crop."""
from concurrent.futures import ThreadPoolExecutor, wait
import threading


class BoundedReaderPool:
    def __init__(self):
        self.executor = ThreadPoolExecutor(max_workers=4, thread_name_prefix='plate-reader')
        self.pending = {}
        self.lock = threading.Lock()

    def read(self, readers, crop, timeout=1.5):
        with self.lock:
            current = {}
            for name, reader in readers.items():
                old = self.pending.get(name)
                if old is not None and not old.done():
                    continue  # Never overlap calls to the same model or queue old crops.
                future = self.executor.submit(reader, crop)
                self.pending[name] = future
                current[name] = future
            done, _ = wait(current.values(), timeout=timeout)
            results = {}
            for name, future in current.items():
                if future in done:
                    try:
                        results[name] = future.result()
                    except Exception:
                        pass
            return results

    def busy(self, name):
        future = self.pending.get(name)
        return future is not None and not future.done()

    def close(self):
        self.executor.shutdown(wait=True, cancel_futures=True)
