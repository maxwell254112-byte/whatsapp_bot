from __future__ import annotations

import logging
from typing import Any

import requests

log = logging.getLogger(__name__)


class ApiClient:
    def __init__(self, base_url: str, token: str, timeout: float = 30.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {token}",
                "Accept": "application/json",
                "User-Agent": "WhatsAppBotWorker/1.0",
            }
        )
        self.timeout = timeout

    def _url(self, path: str) -> str:
        return f"{self.base_url}/{path.lstrip('/')}"

    def _parse(self, resp: requests.Response) -> dict[str, Any]:
        try:
            data = resp.json()
        except Exception:
            data = {"success": False, "message": resp.text[:300], "data": None}
        if not isinstance(data, dict):
            data = {"success": False, "message": "Invalid JSON", "data": None}
        data["_http"] = resp.status_code
        return data

    def auth_check(self) -> dict[str, Any]:
        r = self.session.get(self._url("auth_check.php"), timeout=self.timeout)
        return self._parse(r)

    def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]:
        r = self.session.post(self._url("heartbeat.php"), json=payload, timeout=self.timeout)
        return self._parse(r)

    def claim_job(self) -> dict[str, Any]:
        r = self.session.post(self._url("claim.php"), json={}, timeout=self.timeout)
        return self._parse(r)

    def report(self, job_id: int, result: str, error: str = "") -> dict[str, Any]:
        r = self.session.post(
            self._url("report.php"),
            json={"job_id": job_id, "result": result, "error": error},
            timeout=self.timeout,
        )
        return self._parse(r)

    def download_media(self, media_url: str, dest_path: str) -> None:
        # media_url is absolute from API; still attach bearer
        with self.session.get(media_url, stream=True, timeout=self.timeout) as r:
            r.raise_for_status()
            with open(dest_path, "wb") as f:
                for chunk in r.iter_content(chunk_size=65536):
                    if chunk:
                        f.write(chunk)
