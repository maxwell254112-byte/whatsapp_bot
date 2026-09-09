"""
Centralized WhatsApp Web UI selectors / text probes.
"""

from __future__ import annotations

QR_SELECTORS = [
    "canvas[aria-label*='Scan' i]",
    "div[data-testid='qrcode']",
    "div[data-ref] canvas",
]

CONNECTED_SELECTORS = [
    "div[data-testid='chat-list']",
    "#pane-side",
    "#side",
    "div[aria-label='Chat list']",
    "div[data-testid='chat']",
]

COMPOSER_SELECTORS = [
    "footer div[contenteditable='true'][data-tab='10']",
    "div[contenteditable='true'][data-tab='10']",
    "footer div[contenteditable='true'][role='textbox']",
    "div[contenteditable='true'][role='textbox']",
    "div[contenteditable='true'][data-tab='1']",
    "footer div[contenteditable='true']",
    "div[title='Type a message']",
    "div[aria-label='Type a message']",
    "div[aria-placeholder='Type a message']",
]

CAPTION_SELECTORS = [
    "div[contenteditable='true'][data-tab='10']",
    "div[contenteditable='true'][data-tab='1']",
    "div[data-testid='media-caption-input']",
    "div[aria-label='Add a caption']",
    "div[aria-label='添加说明']",
    "div[contenteditable='true'][role='textbox']",
]

SEND_BUTTON_SELECTORS = [
    "div[data-testid='media-drawer'] button[data-testid='compose-btn-send']",
    "div[data-testid='media-drawer'] span[data-icon='send']",
    "div[data-testid='media-drawer'] [data-testid='send']",
    "span[data-icon='send']",
    "button[data-testid='compose-btn-send']",
    "button[aria-label='Send']",
    "div[role='button'][aria-label='Send']",
    "span[data-testid='send']",
    "button[aria-label='发送']",
    "div[role='button'][aria-label='发送']",
]

OUTGOING_MESSAGE_SELECTORS = [
    "div.message-out",
    "div[data-testid='msg-container']",
]

ATTACH_BUTTON_SELECTORS = [
    "button[data-testid='conversation-clip']",
    "div[title='Attach']",
    "span[data-testid='clip']",
    "button[aria-label='Attach']",
    "button[aria-label='附加']",
    "span[data-icon='plus-rounded']",
    "div[data-testid='conversation-compose-button-plus']",
]

FILE_INPUT_SELECTORS = [
    "input[type='file'][accept*='image']",
    "input[type='file']",
]

MEDIA_PREVIEW_SELECTORS = [
    "div[data-testid='media-drawer']",
    "div[data-animate-media-viewer='true']",
    "div[data-testid='media-editor']",
]

INVALID_NUMBER_TEXTS = [
    "phone number shared via url is invalid",
    "phone number shared via url is invalid.",
    "the phone number shared via url is invalid",
]
