"""Dense plus lexical retrieval with reciprocal-rank fusion."""
import json
from functools import lru_cache
import re
import math

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
    index = _index()
    metadata = json.loads(METADATA_PATH.read_text(encoding="utf-8"))
    vector = embedder().encode([question], normalize_embeddings=True).astype("float32")
    scores, ids = index.search(vector, min(max(TOP_K * 4, 20), len(metadata)))
    dense = [(int(i), float(score)) for score, i in zip(scores[0], ids[0]) if i >= 0 and float(score) >= MIN_SIMILARITY]
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
        row = metadata[i]
        key = re.sub(r"\W+", " ", row.get("text", "").lower()).strip()
        if key in seen: continue
        seen.add(key)
        output.append(dict(row, score=float(fused[i])))
        if len(output) >= TOP_K: break
    return output


@lru_cache(maxsize=1)
def _index():
    return faiss.read_index(str(INDEX_PATH))
