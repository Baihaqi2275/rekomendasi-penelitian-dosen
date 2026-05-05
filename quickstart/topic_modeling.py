import os
import re
from typing import Iterable, List, Tuple

import nltk
from nltk.corpus import stopwords
from nltk.stem import WordNetLemmatizer
from gensim.models import Phrases
from gensim.models.phrases import Phraser
from gensim import corpora, models
import pyLDAvis.gensim_models as gensimvis
import pyLDAvis


# Domain stopwords (Indonesian + generic research terms)
DOMAIN_STOPWORDS = {
    "penelitian", "metode", "hasil", "analisis", "faktor", "data", "studi",
    "menggunakan", "penelitian_ini", "berdasarkan", "tujuan", "sistem", "informasi",
    "peneliti", "kajian", "pendekatan", "kesimpulan", "pembahasan", "pengembangan", 
    "implementasi", "jurnal", "makalah", "artikel", "untuk", "dengan", "dalam", 
    "pada", "dari", "yang", "dan", "untuk", "atau", "sebagai", "juga", "oleh",
    "terhadap", "kepada", "adalah", "karena", "sehingga", "bahwa",
    "berbasis", "evaluasi", "pengaruh", "analisa", "rancang", "bangun",
    "penerapan", "perancangan", "terkait", "tersebut", "secara", "kualitas",
    "kinerja", "tingkat", "proses", "model", "aplikasi", "teknik", "metodologi"
}


def _ensure_nltk():
    nltk.download("stopwords", quiet=True)
    nltk.download("wordnet", quiet=True)


def build_stopwords(extra: Iterable[str] = ()) -> set:
    _ensure_nltk()
    stop_en = set(stopwords.words("english"))
    stop_id = set(stopwords.words("indonesian"))
    return stop_en | stop_id | DOMAIN_STOPWORDS | set(extra)


def normalize_text(text: str) -> str:
    text = text.lower()
    text = re.sub(r"[^\w\s]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def tokenize(text: str) -> List[str]:
    return [tok for tok in text.split() if tok]


def lemmatize_tokens(tokens: List[str], lemmatizer: WordNetLemmatizer) -> List[str]:
    # Simple English lemmatization; Indonesian tokens are left as-is.
    out = []
    for tok in tokens:
        if tok.isalpha():
            out.append(lemmatizer.lemmatize(tok))
        else:
            out.append(tok)
    return out


def preprocess_texts(
    texts: Iterable[str],
    extra_stopwords: Iterable[str] = ()
) -> List[List[str]]:
    stop_set = build_stopwords(extra_stopwords)

    lemmatizer = WordNetLemmatizer()
    cleaned = []

    for text in texts:
        text = normalize_text(text or "")
        tokens = tokenize(text)
        tokens = [t for t in tokens if t not in stop_set and len(t) > 2]
        tokens = lemmatize_tokens(tokens, lemmatizer)
        cleaned.append(tokens)

    return cleaned


def build_bigrams(
    tokenized_texts: List[List[str]],
    min_count: int = 3,
    threshold: int = 10
) -> Tuple[List[List[str]], Phraser]:
    phrases = Phrases(tokenized_texts, min_count=min_count, threshold=threshold, delimiter="_")
    bigram = Phraser(phrases)
    bigram_texts = [bigram[doc] for doc in tokenized_texts]
    return bigram_texts, bigram


def train_lda(
    tokenized_texts: List[List[str]],
    num_topics: int = 8,
    passes: int = 15,
    iterations: int = 200,
    random_state: int = 42
):
    dictionary = corpora.Dictionary(tokenized_texts)
    dictionary.filter_extremes(no_below=3, no_above=0.5)
    corpus = [dictionary.doc2bow(text) for text in tokenized_texts]

    lda_model = models.LdaModel(
        corpus=corpus,
        id2word=dictionary,
        num_topics=num_topics,
        random_state=random_state,
        passes=passes,
        iterations=iterations,
        alpha="auto",
        eta="auto"
    )

    return lda_model, corpus, dictionary


def prepare_pyldavis(lda_model, corpus, dictionary):
    return gensimvis.prepare(lda_model, corpus, dictionary)


def save_pyldavis_html(lda_vis, output_path: str):
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    pyLDAvis.save_html(lda_vis, output_path)

def extract_top_topics(lda_model, num_words: int = 5) -> list:
    """Extract top words from each topic as simple labels."""
    topic_labels = []
    for topic_id in range(lda_model.num_topics):
        # get_topic_terms returns list of (word_id, probability)
        top_terms = lda_model.show_topic(topic_id, topn=num_words)
        words = [word for word, prob in top_terms]
        label = " ".join([word.replace("_", " ").title() for word in words[:3]])
        topic_labels.append(label)
    return topic_labels


def run_topic_modeling_pipeline(
    raw_texts: Iterable[str],
    output_html_path: str,
    extra_stopwords: Iterable[str] = ()
):
    tokenized = preprocess_texts(raw_texts, extra_stopwords=extra_stopwords)
    tokenized, _ = build_bigrams(tokenized)
    lda_model, corpus, dictionary = train_lda(tokenized, num_topics=8)
    lda_vis = prepare_pyldavis(lda_model, corpus, dictionary)
    save_pyldavis_html(lda_vis, output_html_path)
    topic_labels = extract_top_topics(lda_model)
    return lda_model, topic_labels
