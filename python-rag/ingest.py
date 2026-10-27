"""Index approved PDF and TXT files in documents/ (no OCR is performed)."""
import hashlib
import json
import os
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

from config import CHUNK_OVERLAP, CHUNK_SIZE, DATA_DIR, DOCUMENTS_DIR, EMBEDDING_MODEL, EMBED_BATCH_SIZE, INDEX_PATH, METADATA_PATH
from retrieval import embedder
from chunking import chunk_document

def extract(path):
    if path.suffix.lower() == ".pdf":
        import fitz
        with fitz.open(path) as pdf:
            for number, page in enumerate(pdf, 1):
                text = page.get_text("text").strip()
                if text:
                    yield number, text
    elif path.suffix.lower() == ".txt":
        text = path.read_text(encoding="utf-8", errors="replace").strip()
        if text:
            yield None, text


def build_records(documents_dir=DOCUMENTS_DIR):
    documents_dir.mkdir(parents=True, exist_ok=True)
    records, errors = [], []
    for path in sorted(documents_dir.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in {".pdf", ".txt"}:
            continue
        try:
            relative_source = path.relative_to(documents_dir).as_posix()
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            title = path.stem.replace("_", " ").replace("-", " ")
            document_id = hashlib.sha256(relative_source.encode("utf-8")).hexdigest()[:16]
            for page, text in extract(path):
                for number, chunk in enumerate(chunk_document(text, CHUNK_SIZE, CHUNK_OVERLAP)):
                    content_hash = hashlib.sha256(chunk["text"].encode("utf-8")).hexdigest()
                    chunk_id = hashlib.sha256(f"{document_id}:{page}:{number}:{content_hash}".encode("utf-8")).hexdigest()[:24]
                    records.append({"document": path.name, "source": relative_source, "document_id": document_id,
                        "chunk_id": chunk_id, "title": title, "section": chunk["section"], "page": page, "chunk_index": number,
                        "source_type": path.suffix.lower().lstrip("."), "content_hash": content_hash,
                        "chunking_strategy": chunk["strategy"], "embedding_model": EMBEDDING_MODEL,
                        "category": "approved-reference", "version": digest[:12], "text": chunk["text"]})
        except Exception as exc:
            # Never log document text; a malformed input may itself contain PHI.
            errors.append(f"{path.name}: {type(exc).__name__}")
    return records, errors


def main():
    import faiss
    import numpy as np

    DOCUMENTS_DIR.mkdir(parents=True, exist_ok=True)
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    records, errors = build_records(DOCUMENTS_DIR)
    if errors:
        print(f"Ingestion stopped: {len(errors)} source file(s) could not be read. Existing index was preserved.")
        for error in errors:
            print(error)
        return
    if records:
        try:
            vectors = embedder().encode([r["text"] for r in records], normalize_embeddings=True, batch_size=EMBED_BATCH_SIZE).astype("float32")
        except Exception as exc:
            print(f"Ingestion stopped: embeddings failed ({type(exc).__name__}); existing index was preserved.")
            return
        index = faiss.IndexFlatIP(vectors.shape[1])
        index.add(vectors)
        index_tmp = INDEX_PATH.with_suffix(INDEX_PATH.suffix + ".tmp")
        metadata_tmp = METADATA_PATH.with_suffix(METADATA_PATH.suffix + ".tmp")
        try:
            faiss.write_index(index, str(index_tmp))
            metadata_tmp.write_text(json.dumps(records, ensure_ascii=False), encoding="utf-8")
            os.replace(metadata_tmp, METADATA_PATH)
            os.replace(index_tmp, INDEX_PATH)
        finally:
            index_tmp.unlink(missing_ok=True)
            metadata_tmp.unlink(missing_ok=True)
        from retrieval import _index
        _index.cache_clear()
    else:
        for path in (INDEX_PATH, METADATA_PATH):
            path.unlink(missing_ok=True)
    print(f"documents={sum(1 for p in DOCUMENTS_DIR.rglob('*') if p.is_file() and p.suffix.lower() in {'.pdf','.txt'})} chunks={len(records)} errors={len(errors)}")
    for error in errors:
        print(error)


if __name__ == "__main__":
    main()
