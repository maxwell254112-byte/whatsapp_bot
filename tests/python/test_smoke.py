"""Basic worker module smoke tests (no browser / API)."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "worker"
sys.path.insert(0, str(ROOT))

from whatsapp import selectors  # noqa: E402
from version import WORKER_VERSION  # noqa: E402


def test_selectors_nonempty() -> None:
    assert selectors.COMPOSER_SELECTORS
    assert selectors.CONNECTED_SELECTORS
    assert selectors.SEND_BUTTON_SELECTORS


def test_version() -> None:
    assert WORKER_VERSION


if __name__ == "__main__":
    test_selectors_nonempty()
    test_version()
    print("PASS python smoke tests")
