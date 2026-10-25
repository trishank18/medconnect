import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from chunking import split_text, chunk_document


class ChunkingTests(unittest.TestCase):
    def test_normalizes_whitespace_and_keeps_overlap(self):
        chunks = split_text("one  two\nthree four", size=9, overlap=3)
        self.assertEqual(chunks[0], "one two t")
        self.assertEqual(chunks[0][-3:], chunks[1][:3])

    def test_empty_input_produces_no_chunks(self):
        self.assertEqual(split_text("  \n ", size=20, overlap=2), [])

    def test_invalid_overlap_is_rejected(self):
        with self.assertRaises(ValueError):
            split_text("text", size=4, overlap=4)

    def test_chunk_document_keeps_heading_metadata_and_bounds(self):
        chunks = chunk_document("# Care\n\n" + ("This is a sentence about care. " * 20), size=60, overlap=8)
        self.assertGreater(len(chunks), 1)
        self.assertTrue(all(len(c["text"]) <= 60 for c in chunks))
        self.assertTrue(all(c["section"] == "Care" for c in chunks))

    def test_empty_and_duplicate_content(self):
        self.assertEqual(chunk_document("  \n\n", 40, 5), [])
        self.assertEqual(len(chunk_document("same\n\nsame", 40, 5)), 1)

    def test_unicode_and_table_content(self):
        chunks = chunk_document("## Doses\n\n| Drug | Dose |\n| --- | --- |\n| A | 5 mg |\n\nCafé Ω.", 100, 10)
        self.assertIn("5 mg", " ".join(c["text"] for c in chunks))
        self.assertIn("Café Ω.", " ".join(c["text"] for c in chunks))


if __name__ == "__main__":
    unittest.main()
