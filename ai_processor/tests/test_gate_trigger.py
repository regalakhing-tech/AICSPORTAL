"""The worker asks the PHP gate to decide and start the next check when it finishes."""
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

from ai_processor.gate_trigger import trigger_gate_sync


class GateTriggerTests(unittest.TestCase):
    def test_without_php_executable_nothing_runs(self):
        with patch('ai_processor.gate_trigger.subprocess.run') as run:
            self.assertFalse(trigger_gate_sync('', '/app'))
        run.assert_not_called()

    def test_runs_the_gate_tool_quietly_with_a_timeout(self):
        with patch('ai_processor.gate_trigger.subprocess.run') as run:
            run.return_value = subprocess.CompletedProcess([], 0, '', '')
            self.assertTrue(trigger_gate_sync('/usr/local/bin/php', '/app'))
        args, kwargs = run.call_args
        self.assertEqual(args[0], ['/usr/local/bin/php', str(Path('/app') / 'tools' / 'ai-gate-sync.php'), '--quiet'])
        self.assertEqual(kwargs['timeout'], 120)

    def test_timeout_is_reported_not_raised(self):
        with patch('ai_processor.gate_trigger.subprocess.run', side_effect=subprocess.TimeoutExpired('php', 120)):
            with self.assertLogs('ai_processor.gate_trigger', level='WARNING'):
                self.assertFalse(trigger_gate_sync('/usr/local/bin/php', '/app'))

    def test_non_zero_exit_is_reported_not_raised(self):
        with patch('ai_processor.gate_trigger.subprocess.run') as run:
            run.return_value = subprocess.CompletedProcess([], 1, '', 'database down')
            with self.assertLogs('ai_processor.gate_trigger', level='WARNING'):
                self.assertFalse(trigger_gate_sync('/usr/local/bin/php', '/app'))

    def test_missing_php_binary_is_reported_not_raised(self):
        with patch('ai_processor.gate_trigger.subprocess.run', side_effect=FileNotFoundError('php')):
            with self.assertLogs('ai_processor.gate_trigger', level='WARNING'):
                self.assertFalse(trigger_gate_sync('/missing/php', '/app'))


if __name__ == '__main__':
    unittest.main()
