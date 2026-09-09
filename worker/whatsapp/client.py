from __future__ import annotations

import logging
import os
import re
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

from playwright.sync_api import BrowserContext, Page, Playwright, sync_playwright

from . import selectors as S

log = logging.getLogger(__name__)


class WhatsAppSession:
    """Adapter over WhatsApp Web. No anti-detection / stealth features."""

    def __init__(
        self,
        profile_dir: Path,
        screenshot_dir: Path,
        browser_channel: str = "msedge",
        headless: bool = False,
    ) -> None:
        self.profile_dir = profile_dir
        self.screenshot_dir = screenshot_dir
        self.browser_channel = browser_channel
        self.headless = headless
        self._pw: Optional[Playwright] = None
        self.context: Optional[BrowserContext] = None
        self.page: Optional[Page] = None

    def start(self) -> None:
        self._pw = sync_playwright().start()
        launch_args = {
            "user_data_dir": str(self.profile_dir),
            "headless": self.headless,
            "args": ["--disable-dev-shm-usage"],
        }
        # Prefer channel when available (Edge/Chrome); fall back to chromium
        try:
            if self.browser_channel in {"msedge", "chrome"}:
                launch_args["channel"] = self.browser_channel
            self.context = self._pw.chromium.launch_persistent_context(**launch_args)
        except Exception as e:
            log.warning("Channel %s failed (%s); using bundled chromium", self.browser_channel, e)
            launch_args.pop("channel", None)
            self.context = self._pw.chromium.launch_persistent_context(**launch_args)

        self.page = self.context.pages[0] if self.context.pages else self.context.new_page()
        self.page.set_default_timeout(45000)
        # Do NOT force reload if already on WhatsApp during QR wait
        if "web.whatsapp.com" not in (self.page.url or ""):
            self.page.goto("https://web.whatsapp.com/", wait_until="domcontentloaded")

    def close(self) -> None:
        try:
            if self.context:
                self.context.close()
        finally:
            if self._pw:
                self._pw.stop()
            self.context = None
            self.page = None
            self._pw = None

    def screenshot(self, label: str) -> Path:
        assert self.page
        ts = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        path = self.screenshot_dir / f"{ts}_{label}.png"
        try:
            self.page.screenshot(path=str(path), full_page=False)
        except Exception as e:
            log.warning("Screenshot failed: %s", e)
        return path

    def diagnose(self, reason: str) -> dict:
        assert self.page
        info = {
            "reason": reason,
            "url": self.page.url,
            "title": self.page.title(),
        }
        path = self.screenshot(reason.replace(" ", "_")[:40])
        info["screenshot"] = str(path)
        log.error("WA diagnose: %s", info)
        return info

    def detect_qr(self) -> bool:
        assert self.page
        for sel in S.QR_SELECTORS:
            try:
                loc = self.page.locator(sel).first
                if loc.count() and loc.is_visible():
                    # Avoid treating chat canvas as QR: require not connected
                    if not self.detect_connected():
                        return True
            except Exception:
                continue
        return False

    def detect_connected(self) -> bool:
        assert self.page
        for sel in S.CONNECTED_SELECTORS:
            try:
                loc = self.page.locator(sel).first
                if loc.count() and loc.is_visible():
                    return True
            except Exception:
                continue
        return False

    def wait_until_connected(self, timeout_sec: float = 300.0) -> str:
        """
        Wait for connected or QR. Does not reload the page while waiting for QR
        (avoids destroying login / racing with post_logout redirects).
        Returns: connected | qr_required | error
        """
        assert self.page
        deadline = time.time() + timeout_sec
        saw_qr = False
        while time.time() < deadline:
            url = self.page.url or ""
            if "post_logout=1" in url:
                # Navigate once back to root without looping reloads
                log.warning("Detected post_logout URL; navigating to WhatsApp root once")
                self.page.goto("https://web.whatsapp.com/", wait_until="domcontentloaded")
                time.sleep(2)
                continue

            if self.detect_connected():
                return "connected"
            if self.detect_qr():
                saw_qr = True
                # Stay put — do not reload while QR is shown
                time.sleep(2)
                continue
            time.sleep(1.5)
        return "qr_required" if saw_qr else "error"

    def ensure_on_whatsapp(self) -> None:
        assert self.page
        if "web.whatsapp.com" not in (self.page.url or ""):
            self.page.goto("https://web.whatsapp.com/", wait_until="domcontentloaded")

    def send_text_message(self, phone_digits: str, message: str, media_path: Optional[str] = None) -> None:
        """
        Send via deep link. phone_digits must be digits only (no +).
        Raises RuntimeError unless an outgoing message with the text is observed.
        """
        assert self.page
        if not self.detect_connected():
            raise RuntimeError("authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect.")

        phone_digits = "".join(ch for ch in phone_digits if ch.isdigit())
        if not phone_digits:
            raise RuntimeError("Invalid phone digits")

        # Unique marker so we can verify the bubble actually appeared
        # Keep marker INTERNAL only — do not append to recipient-visible text.
        marker = message.strip()
        if len(marker) < 8:
            marker = message.strip() or f"msg{int(time.time())}"
        # Use a short unique suffix only in verification search if message is too generic
        verify_needle = marker[-80:] if len(marker) > 80 else marker
        full_message = message if message.strip() else f"Ping {int(time.time())}"
        if not message.strip():
            verify_needle = full_message

        target = f"https://web.whatsapp.com/send?phone={phone_digits}&text="
        self.page.goto(target, wait_until="domcontentloaded")
        time.sleep(2)

        for _ in range(25):
            url = self.page.url or ""
            if "post_logout=1" in url:
                self.diagnose("post_logout_during_send")
                raise RuntimeError("authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect.")
            if self.detect_qr() and not self.detect_connected():
                raise RuntimeError("authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect.")
            if self._invalid_number_visible():
                self.diagnose("invalid_number")
                raise RuntimeError("Invalid WhatsApp number")
            if self._composer_visible():
                break
            # Continue / Start chat button on some WA versions
            try:
                btn = self.page.get_by_role("button", name=re.compile(r"continue|start chat|继续|开始聊天", re.I))
                if btn.count():
                    btn.first.click(timeout=2000)
            except Exception:
                pass
            time.sleep(1)
        else:
            self.diagnose("composer_timeout")
            raise RuntimeError("Could not open chat composer (WhatsApp UI may have changed)")

        if media_path:
            self._attach_media(path=media_path)
            time.sleep(1.2)
            caption_ok = True
            if full_message.strip():
                caption_ok = self._type_caption(full_message) or self._type_message(full_message)
                if not caption_ok:
                    log.warning("Caption input not found; sending media without caption")
            before = self._main_text()
            if not self._send_media_with_retries():
                self.diagnose("media_preview_stuck")
                raise RuntimeError("Media preview still open after send")
            time.sleep(1.0)
            if caption_ok and full_message.strip():
                # Best-effort caption confirmation; do not fail if image already sent
                self._wait_outgoing_new(verify_needle, before_text=before, timeout_sec=8)
            log.info("Media send finished")
            time.sleep(1.0)
            return

        if not self._type_message(full_message):
            self.diagnose("type_failed")
            raise RuntimeError("Failed to enter message text into composer")

        time.sleep(0.4)
        # Snapshot chat text before send to reduce false positives
        before = self._main_text()
        sent_clicked = self._click_send()
        if not sent_clicked:
            self.page.keyboard.press("Enter")

        if not self._wait_outgoing_new(verify_needle, before_text=before, timeout_sec=20):
            self.diagnose("send_not_confirmed")
            raise RuntimeError(
                "Send not confirmed in chat (message bubble not found). "
                "Check WhatsApp Web window; do not trust false success."
            )

        log.info("Send confirmed in UI")
        time.sleep(1.0)

    def _composer_visible(self) -> bool:
        return self._find_composer() is not None

    def _find_composer(self):
        assert self.page
        for sel in S.COMPOSER_SELECTORS:
            try:
                loc = self.page.locator(sel).last
                if loc.count() and loc.is_visible():
                    return loc
            except Exception:
                continue
        return None

    def _type_message(self, message: str) -> bool:
        assert self.page
        composer = self._find_composer()
        if not composer:
            return False
        try:
            composer.click(timeout=5000)
            time.sleep(0.2)
            # Clear then insert — more reliable than fill() on WA contenteditable
            self.page.keyboard.press("Control+A")
            self.page.keyboard.press("Backspace")
            self.page.keyboard.insert_text(message)
            time.sleep(0.3)
            # Confirm text landed
            try:
                txt = composer.inner_text(timeout=2000) or ""
                if message[:20] in txt or message.split("\n")[0][:20] in txt:
                    return True
            except Exception:
                pass
            # Fallback: type slowly
            self.page.keyboard.press("Control+A")
            self.page.keyboard.press("Backspace")
            self.page.keyboard.type(message, delay=20)
            return True
        except Exception as e:
            log.warning("type_message failed: %s", e)
            return False

    def _main_text(self) -> str:
        assert self.page
        try:
            if self.page.locator("div#main").count():
                return self.page.inner_text("div#main") or ""
            return self.page.inner_text("body") or ""
        except Exception:
            return ""

    def _wait_outgoing_contains(self, marker: str, timeout_sec: float = 20.0) -> bool:
        assert self.page
        deadline = time.time() + timeout_sec
        while time.time() < deadline:
            try:
                body = self._main_text()
                if marker and marker in body:
                    return True
            except Exception:
                pass
            time.sleep(0.8)
        return False

    def _wait_outgoing_new(self, needle: str, before_text: str = "", timeout_sec: float = 20.0) -> bool:
        """Confirm send by detecting needle in chat after send (composer should clear too)."""
        assert self.page
        deadline = time.time() + timeout_sec
        needle = (needle or "").strip()
        snippet = needle[-60:] if len(needle) > 60 else needle
        while time.time() < deadline:
            try:
                # Composer ideally empty after successful send
                composer = self._find_composer()
                composer_empty = True
                if composer:
                    try:
                        composer_empty = len((composer.inner_text(timeout=1000) or "").strip()) == 0
                    except Exception:
                        composer_empty = True
                body = self._main_text()
                appeared = bool(snippet) and snippet in body
                grew = len(body) > max(len(before_text) - 50, 0) and (snippet in body or snippet not in before_text)
                if appeared and (composer_empty or grew):
                    return True
            except Exception:
                pass
            time.sleep(0.8)
        return False

    def _invalid_number_visible(self) -> bool:
        assert self.page
        try:
            text = (self.page.inner_text("body") or "").lower()
        except Exception:
            return False
        return any(t.lower() in text for t in S.INVALID_NUMBER_TEXTS)

    def _attach_media(self, path: str) -> None:
        assert self.page
        if not os.path.isfile(path):
            raise RuntimeError(f"Media file missing: {path}")

        # Prefer Playwright file chooser (most reliable on WhatsApp Web)
        try:
            with self.page.expect_file_chooser(timeout=4000) as fc_info:
                opened = False
                for sel in S.ATTACH_BUTTON_SELECTORS:
                    try:
                        btn = self.page.locator(sel).first
                        if btn.count() and btn.is_visible():
                            btn.click()
                            opened = True
                            break
                    except Exception:
                        continue
                if opened:
                    time.sleep(0.4)
                    try:
                        item = self.page.get_by_text(re.compile(r"photos|videos|相片|照片|视频|Document|文档", re.I)).first
                        if item.count():
                            item.click(timeout=2000)
                    except Exception:
                        pass
            chooser = fc_info.value
            chooser.set_files(path)
            time.sleep(1.5)
            return
        except Exception as e:
            log.warning("file_chooser attach failed (%s); falling back to input", e)

        if self._set_any_file_input(path):
            time.sleep(1.2)
            return

        clicked = False
        for sel in S.ATTACH_BUTTON_SELECTORS:
            try:
                btn = self.page.locator(sel).first
                if btn.count() and btn.is_visible():
                    btn.click()
                    clicked = True
                    break
            except Exception:
                continue
        if not clicked:
            self.diagnose("attach_button_missing")
            raise RuntimeError("Attach button not found")

        time.sleep(0.6)
        try:
            item = self.page.get_by_text(re.compile(r"photos|videos|相片|照片|视频", re.I)).first
            if item.count():
                item.click(timeout=2000)
                time.sleep(0.4)
        except Exception:
            pass

        if not self._set_any_file_input(path):
            self.diagnose("file_input_missing")
            raise RuntimeError("File input not found")
        time.sleep(1.5)

    def _set_any_file_input(self, path: str) -> bool:
        assert self.page
        for sel in S.FILE_INPUT_SELECTORS:
            try:
                inp = self.page.locator(sel)
                count = inp.count()
                for i in range(count):
                    try:
                        inp.nth(i).set_input_files(path)
                        return True
                    except Exception:
                        continue
            except Exception:
                continue
        return False

    def _media_preview_visible(self) -> bool:
        assert self.page
        for sel in S.MEDIA_PREVIEW_SELECTORS:
            try:
                loc = self.page.locator(sel).first
                if loc.count() and loc.is_visible():
                    return True
            except Exception:
                continue
        return False

    def _type_caption(self, message: str) -> bool:
        assert self.page
        for sel in S.CAPTION_SELECTORS:
            try:
                loc = self.page.locator(sel).last
                if not (loc.count() and loc.is_visible()):
                    continue
                loc.click(timeout=3000)
                time.sleep(0.2)
                self.page.keyboard.press("Control+A")
                self.page.keyboard.press("Backspace")
                self.page.keyboard.insert_text(message)
                time.sleep(0.2)
                return True
            except Exception:
                continue
        return False

    def _click_send(self) -> bool:
        assert self.page
        for sel in S.SEND_BUTTON_SELECTORS:
            try:
                btn = self.page.locator(sel).last
                if btn.count() and btn.is_visible():
                    btn.click(timeout=3000, force=True)
                    return True
            except Exception:
                continue
        # Final fallback: any visible send icon
        try:
            icon = self.page.locator("span[data-icon='send']").last
            if icon.count() and icon.is_visible():
                icon.click(force=True)
                return True
        except Exception:
            pass
        return False

    def _send_media_with_retries(self, attempts: int = 5) -> bool:
        """Click send repeatedly until media drawer closes or outgoing image appears."""
        for i in range(attempts):
            self._click_send()
            try:
                self.page.keyboard.press("Enter")
            except Exception:
                pass
            time.sleep(1.5)
            if self._outgoing_image_visible():
                return True
            if not self._media_preview_visible():
                return True
            log.info("Media still in preview; retry send click %s", i + 1)
        return self._outgoing_image_visible() or (not self._media_preview_visible())

    def _outgoing_image_visible(self) -> bool:
        assert self.page
        selectors = [
            "div.message-out img",
            "div[data-testid='msg-container'] img",
            "div[data-testid='image-thumb']",
            "img[src*='blob:']",
        ]
        for sel in selectors:
            try:
                loc = self.page.locator(sel).last
                if loc.count() and loc.is_visible():
                    return True
            except Exception:
                continue
        return False
