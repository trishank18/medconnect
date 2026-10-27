"""Optional live smoke test; never runs in the default test suite without a key."""
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import gemini_service


@unittest.skipUnless(os.getenv("RUN_LIVE_GEMINI") == "1" and os.getenv("GEMINI_API_KEY"), "set RUN_LIVE_GEMINI=1 and GEMINI_API_KEY to run the optional live test")
class LiveGeminiTests(unittest.TestCase):
    def test_configured_model_is_available_and_generates_text(self):
        client = gemini_service._client()
        model = client.models.get(model=gemini_service.GEMINI_MODEL)
        self.assertTrue(model)
        answer = gemini_service.answer_general("Reply with the word ready.")
        self.assertTrue(answer.strip())


if __name__ == "__main__":
    unittest.main()
