"""Persistent cosine-similarity retrieval using normalized MiniLM vectors."""
import json
from functools import lru_cache

import faiss
import numpy as np
from sentence_transformers import SentenceTransformer

from config import EMBEDDING_MODEL, INDEX_PATH, METADATA_PATH, MIN_SIMILARITY, TOP_K


@lru_cache(maxsize=1)
def embedder():
    return SentenceTransformer(EMBEDDING_MODEL)


def retrieve(question: str) -> list[dict]:
    if not INDEX_PATH.exists() or not METADATA_PATH.exists():
        return []
    index = faiss.read_index(str(INDEX_PATH))
    metadata = json.loads(METADATA_PATH.read_text(encoding="utf-8"))
    vector = embedder().encode([question], normalize_embeddings=True).astype("float32")
    scores, ids = index.search(vector, min(TOP_K, len(metadata)))
    return [dict(metadata[i], score=float(score)) for score, i in zip(scores[0], ids[0])
            if i >= 0 and float(score) >= MIN_SIMILARITY]
