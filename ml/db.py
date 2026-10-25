"""MySQL access for live patient measurements.

Credentials are read only from environment variables. This module deliberately
does not contain a fallback database or synthetic data source.
"""
import os
from contextlib import contextmanager
from typing import Any, Iterator


def get_connection() -> Any:
    try:
        import mysql.connector
    except ImportError as exc:
        raise RuntimeError(
            "mysql-connector-python is required for live patient predictions"
        ) from exc

    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        database=os.getenv("DB_NAME", "medconnect"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        connection_timeout=int(os.getenv("DB_CONNECT_TIMEOUT", "5")),
    )


@contextmanager
def connection() -> Iterator[Any]:
    conn = get_connection()
    try:
        yield conn
    finally:
        conn.close()


def fetch_patient_metrics(patient_id: int, limit: int) -> list[dict[str, Any]]:
    if patient_id < 1:
        raise ValueError("patient_id must be a positive integer")
    safe_limit = max(2, min(int(limit), 500))
    query = (
        "SELECT heart_rate, spo2, temperature, blood_pressure_sys, "
        "blood_pressure_dia, recorded_at FROM ("
        "SELECT heart_rate, spo2, temperature, blood_pressure_sys, "
        "blood_pressure_dia, recorded_at, id "
        "FROM health_metrics WHERE patient_id = %s "
        "ORDER BY recorded_at DESC, id DESC LIMIT %s"
        ") AS recent_readings ORDER BY recorded_at ASC, id ASC"
    )
    with connection() as conn:
        cursor = conn.cursor(dictionary=True)
        try:
            cursor.execute(query, (patient_id, safe_limit))
            return list(cursor.fetchall())
        finally:
            cursor.close()
