import argparse
import os
import re
import json
from collections import defaultdict

from sqlalchemy import create_engine, text

from topic_modeling import run_topic_modeling_pipeline


DEFAULT_DB_URL = "mysql+pymysql://root:@localhost/db_siredo"


def normalize_name(name: str) -> str:
    if not name:
        return ""
    name = name.strip()
    name = re.sub(r'^(dr|drg|prof|ir)\.?\s+', '', name, flags=re.IGNORECASE)
    return name


def extract_first_name(name: str) -> str:
    name = normalize_name(name)
    tokens = re.findall(r"[A-Za-z]+", name)
    return tokens[0] if tokens else ""


def fetch_lecturers(engine, code_lec: str | None = None):
    if code_lec:
        q = text("SELECT code_lec, name FROM lecturers WHERE code_lec = :code_lec")
        return engine.execute(q, {"code_lec": code_lec}).fetchall()
    q = text("SELECT code_lec, name FROM lecturers")
    return engine.execute(q).fetchall()


def fetch_publications(engine, author_pattern: str):
    q = text("""
        SELECT title, abstract, author, bahasa
        FROM publication
        WHERE UPPER(author) LIKE UPPER(:pattern)
    """)
    return engine.execute(q, {"pattern": author_pattern}).fetchall()


def build_text(title: str | None, abstract: str | None) -> str:
    title = (title or "").strip()
    abstract = (abstract or "").strip()
    if title and abstract:
        return f"{title}. {abstract}"
    return title or abstract


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--code", dest="code_lec", default=None, help="Filter by lecturer code, e.g., SUZ")
    parser.add_argument("--db", dest="db_url", default=DEFAULT_DB_URL, help="SQLAlchemy DB URL")
    parser.add_argument("--min-docs", dest="min_docs", type=int, default=5, help="Minimum docs to build LDA")
    args = parser.parse_args()

    engine = create_engine(args.db_url)

    with engine.connect() as conn:
        lecturers = fetch_lecturers(conn, args.code_lec)

        for row in lecturers:
            code = (row.code_lec or "").strip().upper()
            name = row.name or ""
            first_name = extract_first_name(name)
            if not code or not first_name:
                continue

            pubs = fetch_publications(conn, f"%{first_name}%")
            if not pubs:
                continue

            grouped = defaultdict(list)
            for p in pubs:
                text_doc = build_text(p.title, p.abstract)
                if not text_doc:
                    continue
                lang = (p.bahasa or "").strip().lower()
                if lang == "id":
                    grouped["id"].append(text_doc)
                elif lang == "en":
                    grouped["en"].append(text_doc)

            if len(grouped.get("id", [])) >= args.min_docs:
                out_path = os.path.join("static", "LDAVis_id", f"{code}.html")
                _, topics_id = run_topic_modeling_pipeline(grouped["id"], out_path)
                with open(os.path.join("static", "LDAVis_id", f"{code}_topics.json"), "w") as f:
                    json.dump(topics_id, f)

            if len(grouped.get("en", [])) >= args.min_docs:
                out_path = os.path.join("static", "LDAVis_en", f"{code}.html")
                _, topics_en = run_topic_modeling_pipeline(grouped["en"], out_path)
                with open(os.path.join("static", "LDAVis_en", f"{code}_topics.json"), "w") as f:
                    json.dump(topics_en, f)


if __name__ == "__main__":
    main()
