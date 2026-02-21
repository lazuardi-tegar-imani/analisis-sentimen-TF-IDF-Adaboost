import os
import sys
import re
import pickle
import pandas as pd
import numpy as np
from flask import Flask, request, jsonify
from flask_cors import CORS
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.tree import DecisionTreeClassifier
from sklearn.ensemble import AdaBoostClassifier
import json
from sklearn.model_selection import train_test_split, StratifiedKFold, cross_val_score, GridSearchCV
from sklearn.metrics import accuracy_score, confusion_matrix, precision_recall_fscore_support
from sklearn.calibration import CalibratedClassifierCV
from sklearn.feature_selection import SelectKBest, chi2
from Sastrawi.Dictionary.ArrayDictionary import ArrayDictionary
from Sastrawi.StopWordRemover.StopWordRemover import StopWordRemover

# Force UTF-8 output on Windows
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

app = Flask(__name__)
CORS(app)

# Inisialisasi Sastrawi
stem_factory = StemmerFactory()
stemmer = stem_factory.create_stemmer()

stop_factory = StopWordRemoverFactory()
# Custom Stopwords: Jangan hapus kata negasi/penting. Tambah subjek (coretax, djp) ke stopword.
more_stopword = ['coretax', 'djp', 'pajak', 'pajakri', 'ditjenpajakri', 'kring_pajak'] 
data = stop_factory.get_stop_words()
# Hapus kata sentimen dari daftar stopword agar tidak hilang (sesuai Bab 3.3.5.d) 
# Update Phase 4: Tambah 'jarang' agar tidak terhapus
exceptions = ['tidak', 'bukan', 'kurang', 'sangat', 'paling', 'lebih', 'bagus', 'jelek', 'baik', 'buruk', 'agak', 'cukup', 'mudah', 'lancar', 'bantu', 'nyaman', 'jarang', 'gak', 'nggak']
data = [w for w in data if w not in exceptions] + more_stopword
dictionary = ArrayDictionary(data)
stopword_remover = StopWordRemover(dictionary)

# Global variables
model = None
vectorizer = None
selector = None
slang_dict = {}

# Emoji Dictionary (Phase 5 - Bab 3.3.5.a)
EMOJI_DICT = {
    '😭': ' sedih ', '😩': ' sedih ', '😠': ' marah ', '😡': ' marah ',
    '😊': ' senang ', '😄': ' senang ', '😍': ' senang ', '👍': ' bagus ',
    '👎': ' buruk ', '👏': ' bagus ', '🙌': ' senang ', '🙏': ' mohon ',
    '❤️': ' cinta ', '🔥': ' keren ', '✨': ' bagus ', '😂': ' lucu '
}

def normalize_emoji(text):
    """Mengubah emoji menjadi representasi teks."""
    for emo, txt in EMOJI_DICT.items():
        text = text.replace(emo, txt)
    return text

def merge_negations(text):
    """Menggabungkan kata negasi dengan kata setelahnya (Phase 5 - Bab 3.3.5)."""
    words = text.split()
    negations = ['tidak', 'bukan', 'gak', 'nggak', 'jarang', 'kurang', 'jangan']
    new_words = []
    skip = False
    for i in range(len(words)):
        if skip:
            skip = False
            continue
        word = words[i]
        if i + 1 < len(words) and word in negations:
            new_words.append(f"{word}_{words[i+1]}")
            skip = True
        else:
            new_words.append(word)
    return ' '.join(new_words)

# Load Slang Dictionary (Phase 3)
def load_slang_dict():
    global slang_dict
    try:
        dict_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'kamus_slang.json')
        if os.path.exists(dict_path):
            with open(dict_path, 'r') as f:
                raw_dict = json.load(f)
                # Flatten the nested dictionary
                for category in raw_dict:
                    for slang, formal in raw_dict[category].items():
                        slang_dict[slang.strip()] = formal.strip()
            print(f"[INFO] Kamus Slang dimuat ({len(slang_dict)} kata).", flush=True)
        else:
            print("[WARNING] kamus_slang.json tidak ditemukan.", flush=True)
    except Exception as e:
        print(f"[ERROR] Gagal memuat kamus slang: {e}", flush=True)

load_slang_dict()

def normalize_slang(text):
    """Mengubah kata slang menjadi kata formal berdasarkan kamus_slang.json."""
    words = text.split()
    normalized_words = [slang_dict.get(word, word) for word in words]
    return ' '.join(normalized_words)

# ============================================================
#  DATASET AWAL
# ============================================================
INITIAL_DATASET = {
    'text': [
        'coretax sangat membantu pelaporan pajak saya',
        'coretax aplikasi yang bagus dan mudah digunakan',
        'coretax mantap untuk urusan pajak',
        'coretax lambat dan sering error',
        'coretax jelek tidak bisa diakses',
        'coretax ribet dan membingungkan',
        'coretax biasa saja tidak ada yang spesial',
        'coretax cukup untuk kebutuhan dasar',
        'coretax tidak buruk tapi juga tidak terlalu bagus',
        'sistem coretax efisien dan cepat',
        'coretax sempurna untuk bisnis kecil',
        'coretax mengecewakan dan penuh bug',
        'coretax buruk sekali tidak recommended',
        'coretax standar tidak istimewa'
    ],
    'sentiment': [
        'positif', 'positif', 'positif',
        'negatif', 'negatif', 'negatif',
        'netral', 'netral', 'netral',
        'positif', 'positif',
        'negatif', 'negatif',
        'netral'
    ]
}

# Targeted Augmentation (Phase 4 - Bab 3.2.2)
# 30 Data manual spesifik pola negasi & intensifier
TARGETED_AUGMENTATION = {
    'text': [
        'coretax tidak error sama sekali', 'sangat lancar jaya coretax ini',
        'tidak sulit aksesnya mudah', 'sungguh membantu pelaporan pajak',
        'gak lambat kok malah cepat', 'jarang gangguan mantap coretax',
        'tidak mengecewakan sangat puas', 'bukan aplikasi jelek tapi bagus',
        'lancar sekali tidak ribet', 'sangat efisien dan memudahkan',
        'tidak membingungkan fiturnya simpel', 'gak ada kendala aman',
        'jarang error sekarang sudah stabil', 'tidak buruk malah oke banget',
        'sangat direkomendasikan untuk pajak', 'tidak berat ringan dijalankan',
        'gak lemot prosesnya instan', 'bukan hoax benar lancar',
        'sangat profesional aplikasinya', 'tidak pelit informasi djp mantap',
        'coretax lemot parah mengecewakan', 'tidak membantu malah ribet',
        'sangat buruk sering force close', 'gak berguna error terus',
        'sungguh jelek tidak recommended', 'jarang lancar sering down',
        'tidak stabil aksesnya lambat', 'bukan solusi malah masalah',
        'sangat mengecewakan respon djp', 'gak ada kemajuan tetep error'
    ],
    'sentiment': [
        'positif', 'positif', 'positif', 'positif', 'positif',
        'positif', 'positif', 'positif', 'positif', 'positif',
        'positif', 'positif', 'positif', 'positif', 'positif',
        'positif', 'positif', 'positif', 'positif', 'positif',
        'negatif', 'negatif', 'negatif', 'negatif', 'negatif',
        'negatif', 'negatif', 'negatif', 'negatif', 'negatif'
    ]
}


# ============================================================
#  PREPROCESSING (dengan log detail per langkah)
# ============================================================
def preprocess_text(text, verbose=True):
    """
    Preprocessing: lowercase, remove special chars, stopword removal, stemming
    Jika verbose=True, setiap langkah akan dicetak ke terminal.
    """
    if verbose:
        print(f"\n{'='*50}", flush=True)
        print(f"[PREPROCESSING] Text Input: \"{text}\"", flush=True)

    # Step 0.5: Emoji Normalization (Phase 5)
    text = normalize_emoji(text)
    if verbose:
        print(f"[STEP 0.5] Emoji Normalization: \"{text}\"", flush=True)

    # Step 1: Case Folding
    text = text.lower()
    if verbose:
        print(f"[STEP 1] Case Folding (Lowercase): \"{text}\"", flush=True)

    # Step 1.5: Slang Normalization (Phase 3 - Bab 3.3.5.a)
    text = normalize_slang(text)
    if verbose:
        print(f"[STEP 1.5] Slang Normalization: \"{text}\"", flush=True)

    # Step 1.6: Negation Merging (Phase 5 - Bab 3.3.5)
    text = merge_negations(text)
    if verbose:
        print(f"[STEP 1.6] Negation Merging: \"{text}\"", flush=True)

    # Step 2: Remove URLs & Twitter Artifacts (Refined Bab 3.3.5.a)
    text = re.sub(r'http\S+|www\S+|https\S+', '', text)
    text = re.sub(r'pic\.twitter\.com\S+|pictwitter\w+', '', text)
    text = re.sub(r'mdash|&lt;|&gt;|&amp;|rt |tweet', '', text)
    if verbose:
        print(f"[STEP 2] Clean URL & Artifacts: \"{text}\"", flush=True)

    # Step 3: Remove Mentions/Hashtags (Mentions are noise in sentiment)
    text = re.sub(r'@\w+|#\w+', '', text)
    if verbose:
        print(f"[STEP 3] Remove Mentions: \"{text}\"", flush=True)

    # Step 4: Remove Numbers & Special Characters (Keep space & underscore for negations)
    text = re.sub(r'[^a-zA-Z\s_]', ' ', text)
    if verbose:
        print(f"[STEP 4] Remove Non-Alpha: \"{text}\"", flush=True)

    # Step 5: Normalize Multiple Spaces
    text = re.sub(r'\s+', ' ', text).strip()
    if verbose:
        print(f"[STEP 5] Normalize Space: \"{text}\"", flush=True)

    # Step 6: Stopword Removal
    text = stopword_remover.remove(text)
    if verbose:
        print(f"[STEP 6] Stopword Removal (Sastrawi): \"{text}\"", flush=True)

    # Step 7: Stemming
    text = stemmer.stem(text)
    if verbose:
        print(f"[STEP 7] Stemming (Sastrawi): \"{text}\"", flush=True)

    return text


# ============================================================
#  LOAD OR TRAIN MODEL
# ============================================================
def load_or_train_model():
    """Cek apakah model sudah ada di disk, jika tidak, latih ulang."""
    global model, vectorizer, selector
    model_path = os.path.join(os.path.dirname(__file__), 'models', 'adaboost_model.pkl')
    vectorizer_path = os.path.join(os.path.dirname(__file__), 'models', 'tfidf_vectorizer.pkl')

    if os.path.exists(model_path) and os.path.exists(vectorizer_path):
        print("\n[INFO] Memuat model dari file .pkl...", flush=True)
        model = pickle.load(open(model_path, 'rb'))
        vectorizer = pickle.load(open(vectorizer_path, 'rb'))
        
        # Load selector if exists (Phase 5)
        selector_path = os.path.join(os.path.dirname(__file__), 'models', 'selector.pkl')
        if os.path.exists(selector_path):
            selector = pickle.load(open(selector_path, 'rb'))
            print("[INFO] Feature selector berhasil dimuat.", flush=True)
            
        print("[INFO] Model dan vectorizer berhasil dimuat.", flush=True)
    else:
        print("\n[INFO] Model belum ada, memulai training...", flush=True)
        train_initial_model()


# ============================================================
#  TRAINING MODEL
# ============================================================
def train_initial_model():
    """Train model dari dataset CSV eksternal atau fallback ke dataset dummy."""
    global model, vectorizer, selector

    # --- LOAD DARI CSV EKSTERNAL (Phase 6: Multi-file support) ---
    external_dfs = []
    base_dir = os.path.dirname(os.path.dirname(__file__))  # OUTPUT TERMINAL/
    dataset_files = [
        os.path.join(base_dir, 'dataset_sentimen_coretax_600.csv'),
        os.path.join(base_dir, 'dataset_tambahan_coretax.csv'),
        os.path.join(base_dir, 'dataset.csv'),
    ]

    for csv_path in dataset_files:
        if os.path.exists(csv_path):
            print(f"[DATASET] Memuat: {csv_path}", flush=True)
            try:
                df_raw = pd.read_csv(csv_path, encoding='utf-8')
                # Normalisasi nama kolom
                df_raw.columns = [c.strip().strip('"').lower() for c in df_raw.columns]
                
                # Map nama kolom (support berbagai variasi nama kolom CSV)
                text_col = None
                sent_col = None
                for c in df_raw.columns:
                    if c in ['text', 'cuitan', 'content', 'tweet', 'ulasan', 'komentar', 'cuitan/text']:
                        text_col = c
                    if c in ['sentiment', 'sentimen', 'label', 'target', 'kelas', 'label/sentimen']:
                        sent_col = c
                
                if text_col and sent_col:
                    temp_df = pd.DataFrame()
                    temp_df['text'] = df_raw[text_col].astype(str).str.strip('"').str.strip()
                    temp_df['sentiment'] = df_raw[sent_col].astype(str).str.strip('"').str.strip().str.lower()
                    
                    # Filter valid labels per file
                    valid_labels = ['positif', 'negatif', 'netral']
                    temp_df = temp_df[temp_df['sentiment'].isin(valid_labels)]
                    temp_df = temp_df[temp_df['text'].str.len() > 0]
                    
                    external_dfs.append(temp_df)
                    print(f"[DATASET] Berhasil memuat {len(temp_df)} baris dari {os.path.basename(csv_path)}.", flush=True)
                else:
                    print(f"[WARNING] Kolom teks/sentimen tidak ditemukan di {csv_path}", flush=True)
            except Exception as e:
                print(f"[ERROR] Gagal membaca CSV {csv_path}: {e}", flush=True)

    if external_dfs:
        df_combined = pd.concat(external_dfs, ignore_index=True)
        print(f"[DATASET] Total data eksternal gabungan: {len(df_combined)} baris.", flush=True)
        
        # Gabungkan dengan INITIAL_DATASET (14 data dummy)
        print("[DATASET] Menggabungkan dengan 14 dataset awal...", flush=True)
        df_dummy = pd.DataFrame(INITIAL_DATASET)
        df = pd.concat([df_combined, df_dummy], ignore_index=True)
    else:
        df = None

    if df is None:
        print("\n[DATASET] Tidak ada CSV eksternal. Menggunakan dataset dummy (14 data).", flush=True)
        df = pd.DataFrame(INITIAL_DATASET)

    # --- TARGETED AUGMENTATION (Phase 4) ---
    print("[DATASET] Menambahkan 30 data augmentasi targeted (Negasi & Intensifier)...", flush=True)
    df_targeted = pd.DataFrame(TARGETED_AUGMENTATION)
    df = pd.concat([df, df_targeted], ignore_index=True)

    # --- DATA AUGMENTATION (Bab 3.2.2) ---
    # Perkuat kelas positif yang sering terkena False Negative
    print("\n[TRAINING] Augmentasi Data Positif (Keywords: membantu, lancar, mudah, bagus)...", flush=True)
    pos_keywords = ['bantu', 'mudah', 'lancar', 'bagus', 'keren', 'mantap', 'oke', 'efisien', 'cepat', 'nyaman']
    df_pos = df[df['sentiment'] == 'positif'].copy()
    # Identifikasi data positif yang memiliki kata kunci kuat
    df_aug = df_pos[df_pos['text'].str.lower().str.contains('|'.join(pos_keywords), na=False)]
    # Gandakan data tersebut 3x agar bobotnya naik
    df = pd.concat([df, df_aug, df_aug], ignore_index=True)
    print(f"[TRAINING] Data setelah augmentasi: {len(df)} baris.", flush=True)

    # Preprocessing (verbose=False agar tidak terlalu panjang saat training banyak data)
    print("\n[TRAINING] Memulai preprocessing...", flush=True)
    df['cleaned_text'] = df['text'].apply(lambda t: preprocess_text(t, verbose=False))
    print(f"[TRAINING] Preprocessing selesai untuk {len(df)} data.", flush=True)

    # TF-IDF Vectorization (Phase 3 Optimization: min_df=2, max_df=0.8)
    print(f"\n[TRAINING] TF-IDF Vectorization (ngram=1-3, min_df=2, max_df=0.8)...", flush=True)
    vectorizer = TfidfVectorizer(max_features=2500, ngram_range=(1, 3), sublinear_tf=True, max_df=0.8, min_df=2)
    X_tfidf = vectorizer.fit_transform(df['cleaned_text'])
    y = df['sentiment']

    # SelectKBest Feature Selection (Phase 5 - Bab 3.2.5)
    print(f"\n[TRAINING] Feature Selection (SelectKBest Chi-Square k=500)...", flush=True)
    selector = SelectKBest(chi2, k=min(500, X_tfidf.shape[1]))
    X = selector.fit_transform(X_tfidf, y)

    # Split Data (Stratified)
    print(f"[TRAINING] Split Data 80:20 (Stratified)...", flush=True)
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

    # 1. Base Model for Comparison (Phase 1 Baseline)
    print(f"\n[TRAINING] Melatih Base Model Comparison (DT max_depth=10)...", flush=True)
    dt_comp = DecisionTreeClassifier(max_depth=10, random_state=42, class_weight='balanced')
    dt_comp.fit(X_train, y_train)
    y_pred_dt = dt_comp.predict(X_test)
    acc_dt = accuracy_score(y_test, y_pred_dt)
    prec_dt, rec_dt, f1_dt, _ = precision_recall_fscore_support(y_test, y_pred_dt, average='macro', zero_division=0)

    # GridSearchCV for Hyperparameters (Phase 5 - Bab 3.2.8)
    print(f"\n[TRAINING] Hyperparameter Tuning dengan GridSearchCV (Phase 5)...", flush=True)
    param_grid = {
        'estimator__max_depth': [5, 10, 15],
        'n_estimators': [50, 100],
        'learning_rate': [0.1, 0.5, 0.8]
    }
    # Base DT with balanced weights
    dt_base = DecisionTreeClassifier(class_weight='balanced', random_state=42)
    adaboost_base = AdaBoostClassifier(estimator=dt_base, algorithm='SAMME', random_state=42)
    
    grid_search = GridSearchCV(adaboost_base, param_grid, cv=3, scoring='accuracy', n_jobs=-1)
    grid_search.fit(X_train, y_train)
    
    best_adaboost = grid_search.best_estimator_
    print(f"[INFO] Best Params: {grid_search.best_params_}", flush=True)

    # 3. Probability Calibration (CalibrateClassifierCV)
    print(f"[TRAINING] Calibrating Probabilities (Sigmoid)...", flush=True)
    model = CalibratedClassifierCV(best_adaboost, method='sigmoid', cv=5)
    model.fit(X_train, y_train)

    # --- CROSS VALIDATION (Phase 3 - Bab 3.2.9) ---
    print(f"\n[VALIDATION] Melakukan Stratified K-Fold Cross Validation (k=5)...", flush=True)
    skf = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
    # Gunakan model terkalibrasi untuk CV agar representatif
    cv_scores = cross_val_score(model, X, y, cv=skf)
    print(f"[VALIDATION] CV Scores: {cv_scores}", flush=True)
    print(f"[VALIDATION] Average Accuracy: {cv_scores.mean()*100:.2f}% (+/- {cv_scores.std()*2*100:.2f}%)", flush=True)
    
    # Evaluasi Final Model
    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)
    prec, rec, f1, _ = precision_recall_fscore_support(
        y_test, y_pred, average='macro', zero_division=0
    )

    # --- REPORTING ---
    print(f"\n{'='*58}")
    print(" 1. HASIL GRID SEARCH & OPTIMISASI")
    print(f"{'='*58}")
    print(f"Best Max Depth (DT)  : {grid_search.best_params_['estimator__max_depth']}")
    print(f"Best N Estimators    : {grid_search.best_params_['n_estimators']}")
    print(f"Best Learning Rate   : {grid_search.best_params_['learning_rate']}")

    print(f"\n{'='*58}")
    print(" 2. EVALUASI PERFORMA: ENSEMBLE MODEL (ADABOOST + CALIBRATION)")
    print(f"{'='*58}")
    print(f"Accuracy (AdaBoost): {accuracy*100:.2f}%")

    # --- 3. TABEL PERBANDINGAN ---
    print(f"\n\n+----------------------------------------------------------+")
    print(f"|      TABEL PERBANDINGAN: DECISION TREE VS ADABOOST       |")
    print(f"+--------------------+-----------------+-------------------+")
    print(f"| Metrik             |  Decision Tree  |     AdaBoost      |")
    print(f"+--------------------+-----------------+-------------------+")
    print(f"| Accuracy           |     {acc_dt*100:6.2f}%      |      {accuracy*100:6.2f}%       |")
    print(f"| Precision (Macro)  |     {prec_dt*100:6.2f}%      |      {prec*100:6.2f}%       |")
    print(f"| Recall (Macro)     |     {rec_dt*100:6.2f}%      |      {rec*100:6.2f}%       |")
    print(f"| F1-Score (Macro)   |     {f1_dt*100:6.2f}%      |      {f1*100:6.2f}%       |")
    print(f"+--------------------+-----------------+-------------------+")
    print(f"| Peningkatan Akurasi: {(accuracy - acc_dt) * 100:+6.2f}%                     |")
    print(f"+----------------------------------------------------------+", flush=True)

    # --- 4. CONFUSION MATRIX ---
    print(f"\n\n+----------------------------------------------------------+")
    print(f"|      DETAIL CONFUSION MATRIX PER KELAS (ADABOOST)        |")
    print(f"+--------------+--------+--------+--------+----------------+")
    print(f"| Kelas        |   TP   |   TN   |   FP   |       FN       |")
    print(f"+--------------+--------+--------+--------+----------------+")

    labels = sorted(df['sentiment'].unique())
    cm = confusion_matrix(y_test, y_pred, labels=labels)

    for i, label in enumerate(labels):
        tp = cm[i, i]
        fp = cm[:, i].sum() - tp
        fn = cm[i, :].sum() - tp
        tn = cm.sum() - (tp + fp + fn)
        print(f"| {label:12} | {tp:6} | {tn:6} | {fp:6} | {fn:14} |")
    print(f"+--------------+--------+--------+--------+----------------+", flush=True)

    # --- 5. HYPERPARAMETERS ---
    print(f"\n\n+----------------------------------------------------------+")
    print(f"|        HYPERPARAMETERS (THESIS CONSTRAINTS)              |")
    print(f"+------------------------------+---------------------------+")
    print(f"| Base Estimator               | Decision Tree             |")
    print(f"| max_depth                    | {grid_search.best_params_['estimator__max_depth']:<25} |")
    print(f"| n_estimators                 | {grid_search.best_params_['n_estimators']:<25} |")
    print(f"| learning_rate                | {grid_search.best_params_['learning_rate']:<25} |")
    print(f"| feature_selection            | SelectKBest (Chi2, k=500) |")
    print(f"| algorithm                    | SAMME                     |")
    print(f"| calibration                  | CalibratedClassifierCV    |")
    print(f"| TF-IDF ngram_range           | (1, 3)                    |")
    print(f"| TF-IDF sublinear_tf          | True                      |")
    print(f"| Test Size                    | 0.2 (Stratified)          |")
    print(f"+------------------------------+---------------------------+", flush=True)

    # --- 6. FEATURE IMPORTANCE ---
    print(f"\n\n{'='*50}", flush=True)
    print(" 6. ANALISIS FITUR TERPENTING (FEATURE IMPORTANCE)", flush=True)
    print(f"{'='*50}", flush=True)

    try:
        # Untuk keperluan analisis fitur, kita fit ulang base estimator (AdaBoost)
        # pada data latih penuh, karena CalibratedClassifierCV membungkus model
        # dan membuat akses ke feature_importances agak tricky/berbeda antar versi sklearn.
        print("\n[INFO] Menghitung Feature Importance dari Base AdaBoost Model...", flush=True)
        # Re-fit adaboost_best on training data to get importances
        best_adaboost.fit(X_train, y_train)
        
        importances = best_adaboost.feature_importances_
        # Map back to original feature names through selector
        all_feature_names = vectorizer.get_feature_names_out()
        selected_mask = selector.get_support()
        selected_feature_names = all_feature_names[selected_mask]
        
        feature_importance_df = pd.DataFrame({
            'Feature': selected_feature_names,
            'Importance': importances
        }).sort_values(by='Importance', ascending=False)

        print("Top 10 Kata Paling Berpengaruh dalam Penentuan Sentimen:", flush=True)
        print(feature_importance_df.head(10).to_string(index=False), flush=True)
    except Exception as e:
        print(f"[WARNING] Tidak dapat menampilkan feature importance: {e}", flush=True)
    print(f"{'='*50}\n", flush=True)

    # Simpan model
    models_dir = os.path.join(os.path.dirname(__file__), 'models')
    os.makedirs(models_dir, exist_ok=True)
    pickle.dump(model, open(os.path.join(models_dir, 'adaboost_model.pkl'), 'wb'))
    pickle.dump(vectorizer, open(os.path.join(models_dir, 'tfidf_vectorizer.pkl'), 'wb'))
    pickle.dump(selector, open(os.path.join(models_dir, 'selector.pkl'), 'wb'))
    print(f"\n[SAVED] Model, vectorizer, & selector tersimpan di {models_dir}", flush=True)

    print(f"\n{'='*58}")
    print(" MODEL SIAP DIGUNAKAN!")
    print(f"{'='*58}\n", flush=True)





# ============================================================
#  ENDPOINTS
# ============================================================

@app.route('/predict', methods=['POST'])
def predict():
    """Endpoint untuk prediksi sentimen single text"""
    global model, vectorizer

    print("\n" + "!"*60, flush=True)
    print("!  REQUEST DITERIMA: /predict", flush=True)
    print("!"*60, flush=True)

    try:
        data = request.get_json()
        text = data.get('text', '')

        if not text:
            return jsonify({'error': 'Text is required'}), 400

        # Preprocessing DENGAN log detail per langkah
        cleaned_text = preprocess_text(text, verbose=True)

        # Vectorization
        text_vector_full = vectorizer.transform([cleaned_text])
        
        # Feature Selection (Phase 5)
        text_vector = selector.transform(text_vector_full)

        # Prediksi
        prediction = model.predict(text_vector)[0]
        probabilities = model.predict_proba(text_vector)[0]

        # Class labels
        classes = model.classes_
        prob_dict = {classes[i]: float(probabilities[i]) for i in range(len(classes))}

        # --- TF-IDF Analysis ---
        print("\n[TF-IDF ANALYSIS (Selected Features)]", flush=True)
        all_feature_names = vectorizer.get_feature_names_out()
        selected_mask = selector.get_support()
        selected_feature_names = all_feature_names[selected_mask]
        
        tfidf_scores = text_vector.toarray()[0]
        active_features = {selected_feature_names[i]: round(float(tfidf_scores[i]), 4)
                           for i in tfidf_scores.nonzero()[0]}
        if active_features:
            for word, score in sorted(active_features.items(), key=lambda x: -x[1]):
                print(f"  - \"{word}\": {score}", flush=True)
        else:
            print("  (Tidak ada kata yang cocok dengan vocabulary model)", flush=True)

        # --- Hasil Klasifikasi ---
        confidence = max(probabilities) * 100
        print(f"\n[KLASIFIKASI HASIL]", flush=True)
        print(f"  Probabilities : {prob_dict}", flush=True)
        print(f"  Confidence    : {confidence:.2f}%", flush=True)
        print(f"  >>> PREDICTED SENTIMENT: {prediction.upper()} <<<", flush=True)
        print("!"*60 + "\n", flush=True)

        return jsonify({
            'sentiment': prediction,
            'probabilities': prob_dict,
            'original_text': text,
            'cleaned_text': cleaned_text
        })

    except Exception as e:
        print(f"ERROR IN /predict: {str(e)}", flush=True)
        return jsonify({'error': str(e)}), 500


@app.route('/predict_batch', methods=['POST'])
def predict_batch():
    """Endpoint untuk prediksi batch (multiple texts)"""
    global model, vectorizer

    print("\n" + "!"*60, flush=True)
    print("!  REQUEST DITERIMA: /predict_batch", flush=True)
    print("!"*60, flush=True)

    try:
        data = request.get_json()
        texts = data.get('texts', [])

        if not texts:
            return jsonify({'error': 'Texts array is required'}), 400

        results = []

        print(f"\n{'='*20} BATCH PROCESSING STARTED {'='*20}", flush=True)
        print(f"Total: {len(texts)} teks akan diproses...\n", flush=True)

        for idx, text in enumerate(texts, 1):
            print(f"--- [{idx}/{len(texts)}] ---", flush=True)
            cleaned_text = preprocess_text(text, verbose=True)
            text_vector = vectorizer.transform([cleaned_text])
            prediction = model.predict(text_vector)[0]
            probabilities = model.predict_proba(text_vector)[0]

            classes = model.classes_
            prob_dict = {classes[i]: float(probabilities[i]) for i in range(len(classes))}
            confidence = max(probabilities) * 100

            print(f"  >>> HASIL: {prediction.upper()} (Confidence: {confidence:.2f}%)", flush=True)

            results.append({
                'original_text': text,
                'sentiment': prediction,
                'probabilities': prob_dict
            })

        # Summary
        positif_count = sum(1 for r in results if r['sentiment'] == 'positif')
        negatif_count = sum(1 for r in results if r['sentiment'] == 'negatif')
        netral_count = sum(1 for r in results if r['sentiment'] == 'netral')

        print(f"\n+{'='*45}+", flush=True)
        print(f"| BATCH PROCESSING SUMMARY ({len(texts)} data)", flush=True)
        print(f"| Positif: {positif_count} | Negatif: {negatif_count} | Netral: {netral_count}", flush=True)
        print(f"+{'='*45}+\n", flush=True)

        return jsonify({'results': results})

    except Exception as e:
        print(f"ERROR IN /predict_batch: {str(e)}", flush=True)
        return jsonify({'error': str(e)}), 500


    print("\n" + "#"*60, flush=True)
    print("#  DYNAMIC RETRAINING: Updating Model in Memory...", flush=True)
    print("#"*60, flush=True)
    try:
        data = request.get_json()
        new_data = data.get('data', [])

        if not new_data:
            return jsonify({'error': 'Training data is required'}), 400

        df = pd.DataFrame(new_data)
        df['cleaned_text'] = df['text'].apply(lambda t: preprocess_text(t, verbose=False))
        
        # In-memory update only
        X = vectorizer.transform(df['cleaned_text'])
        y = df['sentiment']

        model.fit(X, y)
        
        print("\n[SUCCESS] Model has been updated in memory for this session.", flush=True)
        print("Note: This update will be lost if the server is restarted.\n", flush=True)

        return jsonify({
            'message': 'Model updated in memory (Session-Only)',
            'updated_rows': len(df)
        })

    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/model_info', methods=['GET'])
def model_info():
    """Get model information"""
    try:
        info = {
            'algorithm': 'AdaBoost with Decision Tree',
            'base_estimator': 'Decision Tree',
            'n_estimators': model.n_estimators if model else 0,
            'classes': model.classes_.tolist() if model else [],
            'feature_count': len(vectorizer.get_feature_names_out()) if vectorizer else 0
        }
        return jsonify(info)
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'model_loaded': model is not None,
        'vectorizer_loaded': vectorizer is not None
    })


# ============================================================
#  MAIN
# ============================================================
if __name__ == '__main__':
    load_or_train_model()
    print("\n[READY] API Sentimen aktif di http://127.0.0.1:5000", flush=True)
    print("Menunggu input dari user...\n", flush=True)
    app.run(host='0.0.0.0', port=5000, debug=False)