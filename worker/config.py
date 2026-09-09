from __future__ import annotations

import configparser
import os
from dataclasses import dataclass
from pathlib import Path


@dataclass
class WorkerConfig:
    api_base_url: str
    worker_token: str
    profile_dir: Path
    browser_channel: str
    headless: bool
    poll_interval_seconds: float
    heartbeat_interval_seconds: float
    screenshot_dir: Path
    temp_dir: Path


def load_config(path: str | None = None) -> WorkerConfig:
    base = Path(__file__).resolve().parent
    cfg_path = Path(path) if path else base / "config.ini"
    if not cfg_path.is_file():
        example = base / "config.example.ini"
        raise FileNotFoundError(
            f"Missing {cfg_path}. Copy {example.name} to config.ini and set worker_token."
        )

    parser = configparser.ConfigParser()
    parser.read(cfg_path, encoding="utf-8")

    profile = Path(parser.get("worker", "profile_dir", fallback=str(base / "wa_profile")))
    if not profile.is_absolute():
        profile = base / profile

    screenshots = Path(parser.get("worker", "screenshot_dir", fallback=str(base / "screenshots")))
    if not screenshots.is_absolute():
        screenshots = base / screenshots

    temp = Path(parser.get("worker", "temp_dir", fallback=str(base / "temp")))
    if not temp.is_absolute():
        temp = base / temp

    profile.mkdir(parents=True, exist_ok=True)
    screenshots.mkdir(parents=True, exist_ok=True)
    temp.mkdir(parents=True, exist_ok=True)

    token = parser.get("api", "worker_token", fallback="").strip()
    # Prefer environment variable to avoid accidental commits
    token = os.environ.get("WABOT_WORKER_TOKEN", token).strip()
    if not token:
        raise ValueError("worker_token is required (config.ini or WABOT_WORKER_TOKEN)")

    return WorkerConfig(
        api_base_url=parser.get("api", "base_url").rstrip("/"),
        worker_token=token,
        profile_dir=profile,
        browser_channel=parser.get("worker", "browser_channel", fallback="msedge"),
        headless=parser.getboolean("worker", "headless", fallback=False),
        poll_interval_seconds=parser.getfloat("worker", "poll_interval_seconds", fallback=5.0),
        heartbeat_interval_seconds=parser.getfloat("worker", "heartbeat_interval_seconds", fallback=20.0),
        screenshot_dir=screenshots,
        temp_dir=temp,
    )
