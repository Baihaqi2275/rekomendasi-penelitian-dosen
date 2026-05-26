# Import Library yang dibutuhkan
from flask import Flask, render_template, request, jsonify, redirect, url_for
import pickle
import re
import pandas as pd
import nltk
from nltk.stem import WordNetLemmatizer
from sklearn.exceptions import NotFittedError
from sklearn.utils.validation import check_is_fitted
from sqlalchemy import create_engine, text
import os

app = Flask(__name__)

# Menerapkan Lemmatizer
nltk.download('wordnet', quiet=True)
lemmatizer = WordNetLemmatizer()

# Load model
with open('ModelNBC.pkl', 'rb') as f:
    model_hybrid = pickle.load(f)
print("Model Hybrid (ModelNBC.pkl) berhasil diload")

with open('Model-NBC-Multiclass.pkl', 'rb') as f:
    model_one_vs_rest = pickle.load(f)
print("Model One-vs-Rest (Model-NBC-Multiclass.pkl) berhasil diload")

model_accuracy = getattr(model_hybrid, 'accuracy', None)

try:
    check_is_fitted(model_hybrid)
    print("Model sudah fit")
except Exception as e:
    print("Model belum fit:", e)

# Koneksi database
engine = create_engine("mysql+pymysql://root:@127.0.0.1/db_siredo?charset=utf8mb4")

# Ambil data dosen
df_lecturer = pd.read_sql(
    "SELECT code_lec, name, departments_id, expertise, nip, nidn, email FROM lecturers",
    engine
)

# Bersihkan karakter aneh di nama (biasanya hasil encoding yang salah)
df_lecturer['name'] = df_lecturer['name'].str.replace(r'\?+', "'", regex=True)

code_lec_to_name = dict(zip(df_lecturer['code_lec'], df_lecturer['name']))

name_to_code = {
    (name or '').strip().lower(): code
    for code, name in code_lec_to_name.items()
}

# Ambil department
df_departments = pd.read_sql(
    "SELECT id, name_dept FROM departments",
    engine
)

dept_id_to_name = dict(zip(df_departments['id'], df_departments['name_dept']))

code_lec_to_dept = dict(
    (row['code_lec'], dept_id_to_name.get(row['departments_id'], '-'))
    for _, row in df_lecturer.iterrows()
)

# Ambil keyword dosen
query = "SELECT * FROM lecturer_keywords"
df = pd.read_sql(query, engine)

pivot_df = df.pivot_table(index='keyword', columns='code_lec', values='freq', fill_value=0)


def clean_optional_text(value):
    if value is None:
        return ""
    text_value = str(value).strip()
    if text_value.lower() in {"", "-", "none", "nan", "null"}:
        return ""
    return text_value


def build_keyword_expertise_map(keyword_df, max_keywords=8):
    """Use lecturer keywords as a readable expertise fallback when the DB field is empty."""
    if keyword_df.empty:
        return {}

    sorted_keywords = keyword_df.sort_values(["code_lec", "freq", "keyword"], ascending=[True, False, True])
    keyword_map = {}
    for code, group in sorted_keywords.groupby("code_lec"):
        keywords = []
        seen = set()
        for keyword in group["keyword"].dropna():
            keyword_text = clean_optional_text(keyword).replace("_", " ")
            if not keyword_text:
                continue
            normalized = keyword_text.lower()
            if normalized in seen:
                continue
            seen.add(normalized)
            keywords.append(keyword_text.title() if len(keyword_text) <= 4 else keyword_text)
            if len(keywords) >= max_keywords:
                break
        if keywords:
            keyword_map[code] = ", ".join(keywords)
    return keyword_map


keyword_expertise_map = build_keyword_expertise_map(df)


def resolve_lecturer_expertise(lecturer_code, raw_expertise=None):
    expertise = clean_optional_text(raw_expertise)
    if expertise:
        return expertise
    return keyword_expertise_map.get(lecturer_code, "No expertise information available")


# Mapping expertise
df_lecturer['expertise'] = df_lecturer.apply(
    lambda row: resolve_lecturer_expertise(row['code_lec'], row.get('expertise')),
    axis=1
)
code_lec_to_expertise = dict(zip(df_lecturer['code_lec'], df_lecturer['expertise']))


def get_recent_publications(connection, lecturer_name):
    """Fetch total publications count and 10 latest publications by matching lecturer name in author column."""
    if not lecturer_name:
        return 0, []

    clean_name = re.sub(r'^(dr|drg|prof|ir)\.?\s+', '', lecturer_name.strip(), flags=re.IGNORECASE)
    name_tokens = re.findall(r"[A-Za-z]+", clean_name)
    first_name = name_tokens[0] if name_tokens else ""

    if not first_name:
        return 0, []

    query_conditions = []
    query_params = {}
    for i, token in enumerate(name_tokens):
        if len(token) > 2:  # ignore extremely short tokens/initials
            param_name = f"author_pattern_{i}"
            query_conditions.append(f"UPPER(author) LIKE UPPER(:{param_name})")
            query_params[param_name] = f"%{token}%"
            
    if not query_conditions:
        # Fallback if no valid token
        query_conditions.append("UPPER(author) LIKE UPPER(:author_pattern)")
        query_params["author_pattern"] = f"%{first_name}%"

    where_clause = " AND ".join(query_conditions)

    # Get total count of distinct publications
    query_count = text(f"""
        SELECT COUNT(DISTINCT title) AS total_count
        FROM publication
        WHERE {where_clause}
    """)
    count_result = connection.execute(query_count, query_params)
    total_count = count_result.scalar() or 0

    # Get top 10 distinct publications
    query_publications = text(f"""
        SELECT title, NULL AS year, MAX(linkURL) AS linkURL
        FROM publication
        WHERE {where_clause}
        GROUP BY title
        LIMIT 10
    """)
    result = connection.execute(
        query_publications,
        query_params
    )
    pubs = [row._asdict() for row in result.fetchall()]
    return total_count, pubs


def get_lda_paths(lecturer_code):
    """Return relative static paths for LDAVis (ID/EN) if files exist."""
    code = (lecturer_code or "").strip().upper()
    if not code:
        return None, None

    lda_id_rel = f"LDAVis_id/{code}.html"
    lda_en_rel = f"LDAVis_en/{code}.html"

    lda_id_abs = os.path.join(app.static_folder, "LDAVis_id", f"{code}.html")
    lda_en_abs = os.path.join(app.static_folder, "LDAVis_en", f"{code}.html")

    lda_id_path = lda_id_rel if os.path.isfile(lda_id_abs) else None
    lda_en_path = lda_en_rel if os.path.isfile(lda_en_abs) else None

    # Load topic labels if available
    topics_id_path = os.path.join(app.static_folder, "LDAVis_id", f"{code}_topics.json")
    topics_en_path = os.path.join(app.static_folder, "LDAVis_en", f"{code}_topics.json")
    
    import json
    topics_id = []
    if os.path.isfile(topics_id_path):
        with open(topics_id_path, "r") as f:
            topics_id = json.load(f)
            
    topics_en = []
    if os.path.isfile(topics_en_path):
        with open(topics_en_path, "r") as f:
            topics_en = json.load(f)

    return lda_id_path, lda_en_path, topics_id, topics_en


# =============================
# SISTEM REKOMENDASI
# =============================
def sistemrekomendasi_hybrid(keyword_list, df, model, pivot_df):

    try:

        clean_word = re.sub(r'[^\w\s,\.&]', '', keyword_list.lower())

        topics = []
        for topic in clean_word.replace('.', ',').replace('&', ',').split(','):
            topic = topic.strip()
            if topic:
                topics.append(topic)

        word_list_lemma = []
        for topic in topics:
            topic_words = topic.split()
            topic_lemma = [lemmatizer.lemmatize(word) for word in topic_words]
            word_list_lemma.extend(topic_lemma)

        lecturer_keywords = {}

        for keyword in word_list_lemma:

            keyword_data = df[df['keyword'] == keyword]

            for _, row in keyword_data.iterrows():

                lecturer_code = row['code_lec']

                if lecturer_code not in lecturer_keywords:
                    lecturer_keywords[lecturer_code] = set()

                lecturer_keywords[lecturer_code].add(keyword)

        matching_lecturers = []

        for lecturer_code, keywords in lecturer_keywords.items():

            if len(keywords) == len(word_list_lemma):
                matching_lecturers.append(lecturer_code)

        if not matching_lecturers:
            return {
                'message': f"Tidak Ada Dosen yang Memiliki Semua Kata Kunci: {keyword_list}",
                'results': []
            }

        line = df[
            df['code_lec'].isin(matching_lecturers) &
            df['keyword'].isin(word_list_lemma)
        ]

        frequency = line.groupby('code_lec')['freq'].sum()

        expected_cols = getattr(model, 'feature_names_in_', pivot_df.columns)
        fitur_df = frequency.reindex(expected_cols, fill_value=0).values.reshape(1, -1)

        if hasattr(model, 'n_features_in_') and fitur_df.shape[1] != model.n_features_in_:
            import numpy as np
            if fitur_df.shape[1] < model.n_features_in_:
                pad_width = model.n_features_in_ - fitur_df.shape[1]
                fitur_df = np.pad(fitur_df, ((0,0), (0, pad_width)), mode='constant')
            else:
                fitur_df = fitur_df[:, :model.n_features_in_]

        proba = model.predict_proba(fitur_df)[0]
        kelas = model.classes_

        sort_lec = frequency.sort_values(ascending=False).index.tolist()

        results = []

        for lecturer in sort_lec:

            if lecturer in kelas:

                idx = list(kelas).index(lecturer)

                # ORIGINAL: score = proba[idx]
                
                # SMOOTHING: Gabungkan probabilitas model (40%) dengan frekuensi kata kunci (60%)
                # Ini dilakukan agar hasil tidak "Winner-Takes-All" (100% vs 0%)
                model_prob = proba[idx]
                max_freq = frequency.max() if not frequency.empty else 1
                freq_score = frequency.get(lecturer, 0) / max_freq
                score = (model_prob * 0.4) + (freq_score * 0.6)

                results.append((lecturer, score))

        sort_results = sorted(results, key=lambda x: x[1], reverse=True)

        results_formatted = [
            {
                'lecture': code_lec_to_name.get(d, d),
                'code_lec': d,
                'score': round(s, 4),
                'department': code_lec_to_dept.get(d, '-'),
                'expertise': code_lec_to_expertise.get(d, '-'),
                'matched_keywords': list(lecturer_keywords.get(d, []))
            }
            for d, s in sort_results
        ]

        return {
            'message': f"Rekomendasi untuk kata kunci: {' '.join(word_list_lemma)}",
            'results': results_formatted
        }

    except Exception as e:

        return {
            'message': f"Terjadi error: {str(e)}",
            'results': []
        }


# =============================
# API REKOMENDASI
# =============================
@app.route('/rekomendasi', methods=['POST'])
def api_rekomendasi():

    data = request.json

    keyword = data.get('kata_kunci', '')

    approach = (data.get('approach') or 'hybrid').lower()

    if not keyword:
        return jsonify({'error': 'Masukkan kata kunci yang valid'}), 400

    if approach in ['onevsrest', 'ovr']:
        chosen_model = model_one_vs_rest
    else:
        chosen_model = model_hybrid

    hasil = sistemrekomendasi_hybrid(keyword, df, chosen_model, pivot_df)

    # Inject Research Context (Publications & Topics)
    if 'results' in hasil and hasil['results']:
        try:
            with engine.connect() as connection:
                for res in hasil['results']:
                    # Get recent publications (up to 3)
                    total_count, pubs = get_recent_publications(connection, res['lecture'])
                    res['publications'] = pubs[:3]

                    # Get topic modeling (ID) labels (up to 3)
                    lda_id, lda_en, topics_id, topics_en = get_lda_paths(res['code_lec'])
                    res['topics'] = topics_id[:3] if topics_id else []
        except Exception as e:
            print(f"Error fetching contextual data: {e}")

    return jsonify(hasil)


# =============================
# SEARCH PAGE
# =============================
@app.route('/search', methods=['GET'])
def search_page():
    keyword = request.args.get('keyword', '').strip()
    approach = request.args.get('approach', 'hybrid').strip()
    
    query_departments = text("SELECT id, name_dept FROM departments ORDER BY name_dept")
    with engine.connect() as connection:
        deps = connection.execute(query_departments).fetchall()
        departments = [row._asdict() for row in deps]

    return render_template('search.html', keyword=keyword, approach=approach, departments=departments)




# =============================
# HOMEPAGE
# =============================
@app.route('/')
def home():

    query_departments = text("""
        SELECT d.id, d.name_dept, COUNT(l.id) AS count
        FROM departments d
        LEFT JOIN lecturers l ON l.departments_id = d.id
        GROUP BY d.id
        ORDER BY d.name_dept
    """)

    with engine.connect() as connection:

        departments_result = connection.execute(query_departments)

        departments = [row._asdict() for row in departments_result.fetchall()]

    return render_template('index.html', departments=departments)


# =============================
# PROFILE DOSEN
# =============================
@app.route('/profile/<identifier>')
def view_profile(identifier):

    lecturer_code = None

    if identifier in code_lec_to_name:
        lecturer_code = identifier
    else:
        normalized_name = (identifier or '').strip().lower()
        lecturer_code = name_to_code.get(normalized_name)

    if lecturer_code is None:
        return "Lecturer not found", 404

    lecturer_name = code_lec_to_name.get(lecturer_code, identifier)
    # Ensure name is clean
    if '???' in lecturer_name:
        lecturer_name = lecturer_name.replace('???', "'")

    query = text("""
        SELECT l.*, d.name_dept
        FROM lecturers l
        LEFT JOIN departments d ON l.departments_id = d.id
        WHERE l.code_lec = :code_lec
    """)

    with engine.connect() as connection:
        result = connection.execute(query, {"code_lec": lecturer_code})
        lecturer_data = result.fetchone()

        lecturer = {
            'name': lecturer_name,
            'code_lec': lecturer_code,
            'nip': lecturer_data.nip if lecturer_data else None,
            'nidn': lecturer_data.nidn if lecturer_data else None,
            'email': lecturer_data.email if lecturer_data else None,
            'name_dept': lecturer_data.name_dept if lecturer_data else None,
            'expertise': resolve_lecturer_expertise(
                lecturer_code,
                lecturer_data.expertise if lecturer_data else None
            )
        }

        total_publications, publications = get_recent_publications(connection, lecturer_name)

    lda_id_path, lda_en_path, topics_id, topics_en = get_lda_paths(lecturer_code)

    return render_template(
        'viewprofile.html',
        lecturer=lecturer,
        publications=publications,
        total_publications=total_publications,
        lda_id_path=lda_id_path,
        lda_en_path=lda_en_path,
        topics_id=topics_id,
        topics_en=topics_en,
        model_accuracy=model_accuracy
    )


# =============================
# LIST DOSEN
# =============================
@app.route('/lecturers')
def lecturers_page():

    selected_department = (request.args.get('department') or '').strip()

    query = text("""
        SELECT l.code_lec, l.name, l.expertise, l.nip, l.nidn, l.email, d.name_dept
        FROM lecturers l
        LEFT JOIN departments d ON l.departments_id = d.id
        ORDER BY l.name
    """)

    query_departments = text("""
        SELECT d.id, d.name_dept, COUNT(l.id) AS count
        FROM departments d
        LEFT JOIN lecturers l ON l.departments_id = d.id
        GROUP BY d.id
        ORDER BY d.name_dept
    """)

    with engine.connect() as connection:

        lecturers_result = connection.execute(query)

        lecturers = []
        for row in lecturers_result.fetchall():
            d = row._asdict()
            if d.get('name') and '???' in d['name']:
                d['name'] = d['name'].replace('???', "'")
            d['expertise'] = resolve_lecturer_expertise(d.get('code_lec'), d.get('expertise'))
            lecturers.append(d)

        departments_result = connection.execute(query_departments)
        departments = [row._asdict() for row in departments_result.fetchall()]

    return render_template(
        'lecturers.html',
        lecturers=lecturers,
        departments=departments,
        selected_department=selected_department
    )


# =============================
# RUN APP
# =============================
if __name__ == '__main__':
    app.run(debug=True)
