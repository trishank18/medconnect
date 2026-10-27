"""Validated environment-backed configuration for the local RAG service."""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def _integer(name: str, default: int, minimum: int, maximum: int) -> int:
    raw = os.getenv(name, str(default))
    try:
        value = int(raw)
    except (TypeError, ValueError):
        raise RuntimeError(f"Invalid {name}: expected an integer.") from None
    if not minimum <= value <= maximum:
        raise RuntimeError(f"Invalid {name}: value must be between {minimum} and {maximum}.")
    return value


def _number(name: str, default: float, minimum: float, maximum: float) -> float:
    raw = os.getenv(name, str(default))
    try:
        value = float(raw)
    except (TypeError, ValueError):
        raise RuntimeError(f"Invalid {name}: expected a number.") from None
    if not minimum <= value <= maximum:
        raise RuntimeError(f"Invalid {name}: value must be between {minimum} and {maximum}.")
    return value


DOCUMENTS_DIR = Path(os.getenv("RAG_DOCUMENTS_DIR", str(ROOT / "documents"))).resolve()
DATA_DIR = Path(os.getenv("RAG_DATA_DIR", str(ROOT / "data"))).resolve()
INDEX_PATH = DATA_DIR / "knowledge.faiss"
METADATA_PATH = DATA_DIR / "chunks.json"
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "sentence-transformers/all-MiniLM-L6-v2").strip()
GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-3.8-flash").strip()
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "").strip()
SERVICE_TOKEN = os.getenv("RAG_SERVICE_TOKEN", "").strip()
RAG_HOST = os.getenv("RAG_HOST", "127.0.0.1").strip() or "127.0.0.1"
RAG_PORT = _integer("RAG_PORT", 8000, 1, 65535)
TOP_K = _integer("RAG_TOP_K", 5, 1, 20)
MIN_SIMILARITY = _number("RAG_MIN_SIMILARITY", 0.30, -1.0, 1.0)
CHUNK_SIZE = _integer("RAG_CHUNK_SIZE", 900, 100, 10000)
CHUNK_OVERLAP = _integer("RAG_CHUNK_OVERLAP", 150, 0, CHUNK_SIZE - 1)
EMBED_BATCH_SIZE = _integer("RAG_EMBED_BATCH_SIZE", 32, 1, 256)
GEMINI_TIMEOUT_MS = _integer("GEMINI_TIMEOUT_MS", 20000, 1000, 120000)
GEMINI_RETRIES = _integer("GEMINI_RETRIES", 2, 0, 4)
CONVERSATION_TTL_SECONDS = _integer("RAG_CONVERSATION_TTL_SECONDS", 1800, 60, 86400)
MAX_HISTORY_TURNS = _integer("RAG_MAX_HISTORY_TURNS", 6, 0, 12)

if not GEMINI_MODEL or len(GEMINI_MODEL) > 128 or any(ch.isspace() for ch in GEMINI_MODEL):
    raise RuntimeError("Invalid GEMINI_MODEL: set a Gemini API model identifier.")
if not EMBEDDING_MODEL:
    raise RuntimeError("Invalid EMBEDDING_MODEL: set the existing embedding model identifier.")
