"""Ask the PHP submission gate to decide finished requests and start the next queued check."""
import logging
import subprocess
from pathlib import Path

logger = logging.getLogger(__name__)


def trigger_gate_sync(php_executable: str, project_root: str, timeout_seconds: int = 120) -> bool:
    """Best effort: a failure here never fails the AI check itself."""
    if not php_executable:
        return False
    command = [php_executable, str(Path(project_root) / 'tools' / 'ai-gate-sync.php'), '--quiet']
    try:
        completed = subprocess.run(command, capture_output=True, text=True, timeout=timeout_seconds, check=False)
    except (subprocess.TimeoutExpired, OSError) as exc:
        logger.warning('AICS AI gate sync did not run: %s', type(exc).__name__)
        return False
    if completed.returncode != 0:
        logger.warning('AICS AI gate sync failed with exit code %s', completed.returncode)
        return False
    return True
