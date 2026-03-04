import pandas as pd
import re

def is_english(text):
    # Simple heuristic: English often uses many 'the', 'is', 'and', 'are'
    # and fewer 'yang', 'dan', 'di', 'ada', 'dari' (Indonesian common words)
    if not isinstance(text, str):
        return False
    
    text = text.lower()
    en_words = {'the', 'is', 'and', 'are', 'with', 'this', 'that', 'for', 'from'}
    id_words = {'yang', 'dan', 'di', 'ada', 'dari', 'untuk', 'dengan', 'ini', 'itu', 'adalah', 'pajak', 'akan'}
    
    words = set(re.findall(r'\b\w+\b', text))
    en_count = len(words.intersection(en_words))
    id_count = len(words.intersection(id_words))
    
    # If it has significantly more common English words than Indonesian, or has common EN but no ID
    if en_count > id_count:
        return True
    return False

def filter_csv(file_path):
    print(f"Processing {file_path}...")
    try:
        df = pd.read_csv(file_path)
        if 'cuitan' not in df.columns:
            print(f"Error: 'cuitan' column not found in {file_path}")
            return
        
        initial_count = len(df)
        
        # Condition for KEEPING: contains "coretax" AND is NOT English
        # We search case-insensitively for 'coretax'
        mask_contains_coretax = df['cuitan'].str.contains('coretax', case=False, na=False)
        mask_is_not_english = ~df['cuitan'].apply(is_english)
        
        filtered_df = df[mask_contains_coretax & mask_is_not_english]
        
        final_count = len(filtered_df)
        df_to_delete = df[~(mask_contains_coretax & mask_is_not_english)]
        
        print(f"Initial rows: {initial_count}")
        print(f"Rows after filtering: {final_count}")
        print(f"Rows removed: {initial_count - final_count}")
        
        filtered_df.to_csv(file_path, index=False)
        print(f"Successfully updated {file_path}")
        
    except Exception as e:
        print(f"An error occurred: {e}")

files = [
    r'E:\S1-Nursafika\program-analisis-sentimen\Final_Program\dataset_sentimen_coretax_600.csv',
    r'E:\S1-Nursafika\program-analisis-sentimen\Final_Program\dataset_tambahan_coretax.csv'
]

for f in files:
    filter_csv(f)
