"""Dense plus lexical retrieval with reciprocal-rank fusion."""
import json
from functools import lru_cache
import re
import math
import logging

from config import EMBEDDING_MODEL, INDEX_PATH, METADATA_PATH, MIN_SIMILARITY, TOP_K

logger = logging.getLogger("medconnect.rag.retrieval")


@lru_cache(maxsize=1)
def embedder():
    from sentence_transformers import SentenceTransformer
    return SentenceTransformer(EMBEDDING_MODEL)


def retrieve(question: str) -> list[dict]:
    question = " ".join(question.split())
    if not question:
        return []
    if not INDEX_PATH.exists() or not METADATA_PATH.exists():
        return []
    try:
        import numpy as np
        index = _index()
        metadata = json.loads(METADATA_PATH.read_text(encoding="utf-8"))
        if not isinstance(metadata, list) or not metadata or index.ntotal != len(metadata):
            logger.warning("RAG index and metadata are empty or out of sync")
            return []
        indexed_models = {row.get("embedding_model") for row in metadata if isinstance(row, dict) and row.get("embedding_model")}
        if indexed_models and indexed_models != {EMBEDDING_MODEL}:
            logger.error("Configured embedding model differs from the stored index; re-ingestion is required")
            return []
        vector = embedder().encode([question], normalize_embeddings=True).astype("float32")
        if vector.ndim != 2 or vector.shape[0] != 1 or vector.shape[1] != index.d:
            logger.error("RAG embedding dimensions do not match the stored index; re-ingestion is required")
            return []
        scores, ids = index.search(vector, min(max(TOP_K * 4, 20), len(metadata)))
    except Exception as exc:
        logger.warning("RAG retrieval unavailable error_type=%s", type(exc).__name__)
        return []
    dense = [(int(i), float(score)) for score, i in zip(scores[0], ids[0]) if 0 <= i < len(metadata) and float(score) >= MIN_SIMILARITY]
    terms = re.findall(r"[\w'-]+", question.lower())
    lexical = []
    if terms:
        docs = [set(re.findall(r"[\w'-]+", str(row.get("text", "")).lower())) for row in metadata]
        df = {term: sum(term in doc for doc in docs) for term in set(terms)}
        for i, doc in enumerate(docs):
            score = sum(math.log(1 + (len(docs) - df[t] + .5) / (df[t] + .5)) * (1 if t in doc else 0) for t in terms)
            if score: lexical.append((i, score))
        lexical.sort(key=lambda x: x[1], reverse=True)
        lexical = lexical[:max(TOP_K * 4, 20)]
    fused = {}
    for rank, (i, score) in enumerate(dense, 1): fused[i] = fused.get(i, 0) + 1 / (60 + rank)
    for rank, (i, score) in enumerate(lexical, 1): fused[i] = fused.get(i, 0) + 1 / (60 + rank)
    ranked = sorted(fused, key=fused.get, reverse=True)
    output, seen = [], set()
    for i in ranked:
        if i < 0 or i >= len(metadata):
            continue
        row = metadata[i]
        if not isinstance(row, dict) or not str(row.get("text", "")).strip():
            continue
        key = re.sub(r"\W+", " ", row.get("text", "").lower()).strip()
        if key in seen: continue
        seen.add(key)
        output.append(dict(row, score=float(fused[i])))
        if len(output) >= TOP_K: break
    return output


@lru_cache(maxsize=1)
def _index():
    import faiss
    return faiss.read_index(str(INDEX_PATH))
