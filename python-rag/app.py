import hmac
import logging
import uuid
from dotenv import load_dotenv
from pathlib import Path

load_dotenv(Path(__file__).resolve().parent.parent / ".env")
load_dotenv(Path(__file__).resolve().parent / ".env", override=False)

from fastapi import FastAPI, Header, HTTPException
from fastapi.middleware.cors import CORSMiddleware

from config import SERVICE_TOKEN
from gemini_service import answer, answer_general
from retrieval import retrieve
from schemas import ChatRequest, ChatResponse, Source

logging.basicConfig(level=logging.INFO)
app = FastAPI(title="MedConnect RAG", docs_url=None, redoc_url=None)
app.add_middleware(CORSMiddleware, allow_origins=[], allow_methods=[], allow_headers=[])


def authorize(token: str | None) -> None:
    if not SERVICE_TOKEN or not token or not hmac.compare_digest(token, SERVICE_TOKEN):
        raise HTTPException(status_code=401, detail="Unauthorized")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/chat", response_model=ChatResponse)
def chat(payload: ChatRequest, x_medconnect_token: str | None = Header(default=None)):
    authorize(x_medconnect_token)
    question = " ".join(payload.question.split())
    if not question:
        raise HTTPException(status_code=422, detail="Question is required")
    chunks = retrieve(question)
    if not chunks:
        try:
            reply = answer_general(question)
        except Exception as exc:
            logging.warning("General AI fallback failed request_id=%s type=%s", payload.request_id, type(exc).__name__)
            raise HTTPException(status_code=503, detail="AI generation is temporarily unavailable. Please try again shortly.") from None
        return ChatResponse(
            reply=reply + "\n\nGeneral information fallback; this was not answered from approved MedConnect documents.",
            sources=[],
        )
    try:
        reply = answer(question, chunks)
    except Exception as exc:
        logging.warning("RAG generation failed request_id=%s type=%s", payload.request_id, type(exc).__name__)
        raise HTTPException(status_code=503, detail="AI generation is temporarily unavailable. Please try again shortly.") from None
    sources = []
    seen = set()
    for chunk in chunks:
        key = (chunk["document"], chunk.get("page"))
        if key not in seen:
            sources.append(Source(document=key[0], page=key[1]))
            seen.add(key)
    return ChatResponse(reply=reply, sources=sources)
