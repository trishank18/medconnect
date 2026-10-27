"""Authenticated FastAPI boundary for approved-document and general questions."""
import hmac
import logging
import threading
import time
from collections import OrderedDict
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
load_dotenv(Path(__file__).resolve().parent / ".env", override=False)

from fastapi import FastAPI, Header, HTTPException
from fastapi.middleware.cors import CORSMiddleware

from config import CONVERSATION_TTL_SECONDS, MAX_HISTORY_TURNS, RAG_HOST, RAG_PORT, SERVICE_TOKEN
from gemini_service import (
    GeminiServiceError,
    answer,
    answer_general,
    answer_mixed,
    answer_unverified_medconnect,
)
from retrieval import retrieve
from routing import Category, classify_query, has_general_component
from schemas import ChatRequest, ChatResponse, Source

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("medconnect.rag")
app = FastAPI(title="MedConnect RAG", docs_url=None, redoc_url=None)
# The only supported caller is the same-machine PHP bridge, authenticated by a
# server-only token. Browser CORS is intentionally disabled.
app.add_middleware(CORSMiddleware, allow_origins=[], allow_methods=[], allow_headers=[])


class ConversationStore:
    """Small, process-local cache of non-private general/document conversations."""

    def __init__(self, ttl: int, max_turns: int, max_conversations: int = 2048):
        self.ttl = ttl
        self.max_turns = max_turns
        self.max_conversations = max_conversations
        self.items: OrderedDict[str, tuple[float, list[tuple[str, str]]]] = OrderedDict()
        self.lock = threading.Lock()

    def get(self, key: str | None) -> list[tuple[str, str]]:
        if not key or self.max_turns == 0:
            return []
        now = time.monotonic()
        with self.lock:
            self._expire(now)
            item = self.items.get(key)
            if not item:
                return []
            self.items.move_to_end(key)
            return list(item[1])

    def add(self, key: str | None, question: str, reply: str) -> None:
        if not key or self.max_turns == 0:
            return
        now = time.monotonic()
        with self.lock:
            self._expire(now)
            history = list(self.items.get(key, (now, []))[1])
            history.extend((("user", question[:2000]), ("assistant", reply[:4000])))
            self.items[key] = (now, history[-self.max_turns * 2 :])
            self.items.move_to_end(key)
            while len(self.items) > self.max_conversations:
                self.items.popitem(last=False)

    def _expire(self, now: float) -> None:
        for key in [key for key, (created, _) in self.items.items() if now - created > self.ttl]:
            self.items.pop(key, None)


conversations = ConversationStore(CONVERSATION_TTL_SECONDS, MAX_HISTORY_TURNS)


def authorize(token: str | None) -> None:
    if not SERVICE_TOKEN or not token or not hmac.compare_digest(token, SERVICE_TOKEN):
        raise HTTPException(status_code=401, detail="Unauthorized")


@app.get("/health")
def health():
    # Liveness only: do not disclose key configuration or index contents.
    return {"status": "ok"}


@app.post("/chat", response_model=ChatResponse)
def chat(payload: ChatRequest, x_medconnect_token: str | None = Header(default=None)):
    authorize(x_medconnect_token)
    question = " ".join(payload.question.split())
    category = classify_query(question)

    # This service does not accept database identities or patient IDs. PHP must
    # answer private-data requests from role-scoped, parameterized MySQL queries.
    if category in (Category.PATIENT_DATA, Category.DOCTOR_DATA):
        raise HTTPException(status_code=403, detail="Private health records are available only through your authorized MedConnect workspace.")
    if category == Category.AMBIGUOUS_OR_UNAUTHORIZED:
        raise HTTPException(status_code=403, detail="Please select an authorized patient in MedConnect before asking about their records.")

    history = conversations.get(payload.conversation_id)
    try:
        if category == Category.GENERAL_AI:
            reply = answer_general(question, history)
            sources = []
        else:
            chunks = retrieve(question)
            mixed = has_general_component(question)
            if mixed:
                reply = answer_mixed(question, chunks, history)
                sources = []
                seen = set()
                for chunk in chunks:
                    document = str(chunk.get("document", "Approved MedConnect reference"))[:200]
                    page = chunk.get("page")
                    section = str(chunk.get("section") or "")[:200] or None
                    key = (document, page, section)
                    if key not in seen:
                        sources.append(Source(document=document, page=page, section=section))
                        seen.add(key)
            elif chunks:
                reply = answer(question, chunks, history)
                sources = []
                seen = set()
                for chunk in chunks:
                    document = str(chunk.get("document", "Approved MedConnect reference"))[:200]
                    page = chunk.get("page")
                    section = str(chunk.get("section") or "")[:200] or None
                    key = (document, page, section)
                    if key not in seen:
                        sources.append(Source(document=document, page=page, section=section))
                        seen.add(key)
            else:
                reply = answer_unverified_medconnect(question, history)
                sources = []
        if not reply.strip():
            raise GeminiServiceError("empty")
        conversations.add(payload.conversation_id, question, reply)
        return ChatResponse(reply=reply, category=category.value, sources=sources)
    except GeminiServiceError as exc:
        logger.warning("Gemini request unavailable request_id=%s reason=%s", payload.request_id, exc.code)
        raise HTTPException(status_code=503, detail="The assistant is temporarily unavailable. Please try again shortly.") from None
    except Exception as exc:
        # Log only exception type and request ID; never request text, PHI, or
        # provider response bodies/keys.
        logger.error("Chat request failed request_id=%s error_type=%s", payload.request_id, type(exc).__name__)
        raise HTTPException(status_code=503, detail="The assistant is temporarily unavailable. Please try again shortly.") from None


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host=RAG_HOST, port=RAG_PORT)
