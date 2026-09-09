from __future__ import annotations

import logging
import os
import platform
import random
import sys
import time
import traceback
from pathlib import Path

from api_client import ApiClient
from config import load_config
from version import WORKER_VERSION
from whatsapp.client import WhatsAppSession

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
log = logging.getLogger("worker")


class WorkerApp:
    def __init__(self) -> None:
        self.cfg = load_config()
        self.api = ApiClient(self.cfg.api_base_url, self.cfg.worker_token)
        self.wa = WhatsAppSession(
            self.cfg.profile_dir,
            self.cfg.screenshot_dir,
            self.cfg.browser_channel,
            self.cfg.headless,
        )
        self.whatsapp_status = "unknown"
        self.current_job_id: int | None = None
        self._last_heartbeat = 0.0
        self._batch_count = 0
        self._rate = {
            "min_delay_seconds": 3,
            "max_delay_seconds": 8,
            "max_messages_per_batch": 20,
            "pause_between_batches_seconds": 60,
        }

    def run(self) -> None:
        log.info("Worker %s starting", WORKER_VERSION)
        auth = self.api.auth_check()
        if not auth.get("success"):
            log.error("API auth failed: %s", auth.get("message"))
            return

        self.wa.start()
        self.whatsapp_status = self.wa.wait_until_connected(timeout_sec=120)
        if self.whatsapp_status != "connected":
            log.warning(
                "WhatsApp status=%s. Scan QR if shown. Do not close the browser window.",
                self.whatsapp_status,
            )

        try:
            while True:
                self._heartbeat()
                if self.whatsapp_status != "connected":
                    self.whatsapp_status = self.wa.wait_until_connected(timeout_sec=30)
                    time.sleep(self.cfg.poll_interval_seconds)
                    continue

                if self._batch_count >= int(self._rate["max_messages_per_batch"]):
                    pause = float(self._rate["pause_between_batches_seconds"])
                    log.info("Batch pause %.0fs", pause)
                    time.sleep(pause)
                    self._batch_count = 0

                claimed = self.api.claim_job()
                job = (claimed.get("data") or {}).get("job") if claimed.get("success") else None
                if not job:
                    time.sleep(self.cfg.poll_interval_seconds)
                    continue

                self._process_job(job)
                delay = random.uniform(
                    float(self._rate["min_delay_seconds"]),
                    float(self._rate["max_delay_seconds"]),
                )
                time.sleep(delay)
        except KeyboardInterrupt:
            log.info("Shutting down")
        finally:
            self.wa.close()

    def _heartbeat(self) -> None:
        now = time.time()
        if now - self._last_heartbeat < self.cfg.heartbeat_interval_seconds:
            return
        payload = {
            "whatsapp_status": self.whatsapp_status,
            "hostname": platform.node(),
            "os_info": f"{platform.system()} {platform.release()}",
            "browser": self.cfg.browser_channel,
            "python_version": platform.python_version(),
            "worker_version": WORKER_VERSION,
            "last_error": None,
        }
        try:
            resp = self.api.heartbeat(payload)
            if resp.get("success"):
                limits = (resp.get("data") or {}).get("rate_limits") or {}
                self._rate.update({k: limits[k] for k in self._rate if k in limits})
            else:
                log.warning("Heartbeat failed: %s", resp.get("message"))
        except Exception as e:
            log.warning("Heartbeat error: %s", e)
        self._last_heartbeat = now

    def _process_job(self, job: dict) -> None:
        job_id = int(job["id"])
        self.current_job_id = job_id
        media_path = None
        try:
            if not self.wa.detect_connected():
                self.whatsapp_status = "qr_required"
                raise RuntimeError(
                    "authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                )

            if job.get("media_url"):
                ext = str(job.get("media_extension") or "bin").lstrip(".")
                media_path = str(self.cfg.temp_dir / f"job_{job_id}.{ext}")
                self.api.download_media(job["media_url"], media_path)

            self.wa.send_text_message(
                phone_digits=str(job["phone"]),
                message=str(job.get("message_body") or ""),
                media_path=media_path,
            )
            # Ambiguous window: WhatsApp may have accepted before report reaches server.
            resp = self.api.report(job_id, "sent")
            if not resp.get("success"):
                log.error("Report sent failed for job %s: %s", job_id, resp.get("message"))
            else:
                log.info("Job %s reported sent", job_id)
            self._batch_count += 1
        except Exception as e:
            err = str(e)
            log.error("Job %s failed: %s", job_id, err)
            if "authentication_required" in err:
                self.whatsapp_status = "qr_required"
            try:
                self.api.report(job_id, "failed", error=err[:500])
            except Exception as re:
                log.error("Could not report failure for job %s: %s", job_id, re)
            # Avoid tight failure loops
            time.sleep(3)
        finally:
            if media_path and os.path.isfile(media_path):
                try:
                    os.remove(media_path)
                except OSError:
                    pass
            self.current_job_id = None


def main() -> None:
    # Ensure worker dir on path
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    try:
        WorkerApp().run()
    except Exception:
        log.error("Fatal: %s", traceback.format_exc())
        raise


if __name__ == "__main__":
    main()
