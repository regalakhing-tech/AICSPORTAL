import unittest


class FakeClock:
    def __init__(self):
        self.value = 1.0

    def __call__(self):
        current = self.value
        self.value += 0.125
        return current


class StageTimingsTests(unittest.TestCase):
    def test_measure_records_nonnegative_integer_milliseconds(self):
        from ai_processor.timing import StageTimings

        timings = StageTimings(clock=FakeClock())
        with timings.measure('ocr'):
            pass

        self.assertEqual(timings.as_dict(), {'ocr': 125})

    def test_duplicate_stage_names_accumulate(self):
        from ai_processor.timing import StageTimings

        timings = StageTimings(clock=FakeClock())
        with timings.measure('external_ai'):
            pass
        with timings.measure('external_ai'):
            pass

        self.assertEqual(timings.as_dict(), {'external_ai': 250})


if __name__ == '__main__':
    unittest.main()
