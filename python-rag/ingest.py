"""Index approved PDF and TXT files in documents/ (no OCR is performed)."""
import hashlib
import json

import faiss
import fitz
import numpy as np

from config import CHUNK_OVERLAP, CHUNK_SIZE, DATA_DIR, DOCUMENTS_DIR, EMBEDDING_MODEL, INDEX_PATH, METADATA_PATH
from retrieval import embedder
from chunking import split_text


def extract(path):
    if path.suffix.lower() == ".pdf":
        with fitz.open(path) as pdf:
            for number, page in enumerate(pdf, 1):
                text = page.get_text("text").strip()
                if text:
                    yield number, text
    elif path.suffix.lower() == ".txt":
        text = path.read_text(encoding="utf-8", errors="replace").strip()
        if text:
            yield None, text


def main():
    DOCUMENTS_DIR.mkdir(parents=True, exist_ok=True)
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    records, errors = [], []
    for path in sorted(DOCUMENTS_DIR.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in {".pdf", ".txt"}:
            continue
        try:
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            for page, text in extract(path):
                for chunk in split_text(text, CHUNK_SIZE, CHUNK_OVERLAP):
                    records.append({"document": path.name, "page": page, "category": "approved-reference", "version": digest[:12], "text": chunk})
        except Exception as exc:
            errors.append(f"{path.name}: {type(exc).__name__}")
    if records:
        vectors = embedder().encode([r["text"] for r in records], normalize_embeddings=True).astype("float32")
        index = faiss.IndexFlatIP(vectors.shape[1])
        index.add(vectors)
        faiss.write_index(index, str(INDEX_PATH))
        METADATA_PATH.write_text(json.dumps(records, ensure_ascii=False), encoding="utf-8")
    else:
        for path in (INDEX_PATH, METADATA_PATH):
            path.unlink(missing_ok=True)
    print(f"documents={sum(1 for p in DOCUMENTS_DIR.rglob('*') if p.is_file() and p.suffix.lower() in {'.pdf','.txt'})} chunks={len(records)} errors={len(errors)}")
    for error in errors:
        print(error)


if __name__ == "__main__":
    main()
