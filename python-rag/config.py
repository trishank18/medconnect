"""Environment-backed configuration for MedConnect's local RAG service."""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DOCUMENTS_DIR = Path(os.getenv("RAG_DOCUMENTS_DIR", ROOT / "documents"))
DATA_DIR = Path(os.getenv("RAG_DATA_DIR", ROOT / "data"))
INDEX_PATH = DATA_DIR / "knowledge.faiss"
METADATA_PATH = DATA_DIR / "chunks.json"
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-3.8-flash")
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")
SERVICE_TOKEN = os.getenv("RAG_SERVICE_TOKEN", "")
TOP_K = int(os.getenv("RAG_TOP_K", "5"))
MIN_SIMILARITY = float(os.getenv("RAG_MIN_SIMILARITY", "0.30"))
CHUNK_SIZE = int(os.getenv("RAG_CHUNK_SIZE", "900"))
CHUNK_OVERLAP = int(os.getenv("RAG_CHUNK_OVERLAP", "150"))
