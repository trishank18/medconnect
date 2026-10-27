"""A real CPU embedding/FAISS integration check, skipped without RAG dependencies."""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

_HAS_RAG_STACK = all(importlib.util.find_spec(name) for name in ("faiss", "fitz", "numpy", "sentence_transformers"))


@unittest.skipUnless(_HAS_RAG_STACK, "install python-rag/requirements.txt to run the CPU index integration test")
class RealIndexTests(unittest.TestCase):
    def test_document_can_be_ingested_and_retrieved_from_temporary_index(self):
        import ingest
        import retrieval

        with tempfile.TemporaryDirectory(prefix="medconnect-rag-test-") as folder:
            root = Path(folder)
            documents = root / "documents"
            data = root / "index"
            documents.mkdir()
            (documents / "architecture.txt").write_text(
                "# RAG Architecture\n\nMedConnect uses PHP for authenticated patient requests and a Python FastAPI service for approved document retrieval. The browser never sends patient records to Gemini.",
                encoding="utf-8",
            )
            with patch.object(ingest, "DOCUMENTS_DIR", documents), patch.object(ingest, "DATA_DIR", data), patch.object(ingest, "INDEX_PATH", data / "knowledge.faiss"), patch.object(ingest, "METADATA_PATH", data / "chunks.json"), patch.object(retrieval, "INDEX_PATH", data / "knowledge.faiss"), patch.object(retrieval, "METADATA_PATH", data / "chunks.json"):
                ingest.main()
                retrieval._index.cache_clear()
                hits = retrieval.retrieve("How does MedConnect route authenticated requests to Python document retrieval?")
                retrieval._index.cache_clear()

        self.assertTrue(hits)
        self.assertEqual(hits[0]["document"], "architecture.txt")
        self.assertEqual(hits[0]["section"], "RAG Architecture")


if __name__ == "__main__":
    unittest.main()
