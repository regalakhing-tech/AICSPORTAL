"""Privacy-safe elapsed-time collection for document processing stages."""
from contextlib import contextmanager
import time


class StageTimings:
    def __init__(self, clock=time.perf_counter):
        self.clock = clock
        self.started_at = clock()
        self.values: dict[str, int] = {}

    def start(self) -> float:
        return self.clock()

    def finish(self, name: str, started_at: float) -> int:
        elapsed_ms = max(0, round((self.clock() - started_at) * 1000))
        self.values[name] = self.values.get(name, 0) + elapsed_ms
        return elapsed_ms

    @contextmanager
    def measure(self, name: str):
        started_at = self.start()
        try:
            yield
        finally:
            self.finish(name, started_at)

    def as_dict(self) -> dict[str, int]:
        return dict(self.values)

    def total_ms(self) -> int:
        return max(0, round((self.clock() - self.started_at) * 1000))
