"""Print the latest saved evaluation report or retrain/evaluate."""
import json
from pathlib import Path
from train_model import train, REPORTS
if __name__ == "__main__":
    report = train() if len(__import__('sys').argv) > 1 and __import__('sys').argv[1] == "--train" else None
    if report is None:
        files = sorted(REPORTS.glob("rf-*.json")) if REPORTS.exists() else []
        if not files: raise SystemExit("No reports found. Run python train_model.py first.")
        print(Path(files[-1]).read_text(encoding="utf-8"))
