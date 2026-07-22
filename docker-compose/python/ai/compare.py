import numpy as np
import torch
from sentence_transformers import SentenceTransformer, util

# 1. Загружаем мощную мультиязычную модель (скачивается один раз, ~2.2 ГБ)
print("Загрузка модели BAAI/bge-m3...")
# model = SentenceTransformer('BAAI/bge-m3')
# Вместо имени на Hugging Face указываем путь к локальной папке
model = SentenceTransformer('./bge_m3_local')


# Пример глав или абзацев из книг на разных языках
book_english = [
    "The main character decided to leave the town early in the morning.",
    "He packed his silver pocket watch and a few old books.",
    "The weather was rainy, and the wind blew heavily.",
    "All right then.",
    "Well?"
]

book_russian = [
    "Главный герой решил покинуть город рано утром.",                     
    "Погода была дождливой, и дул сильный ветер.",                        
    "Он упаковал свои серебряные карманные часы и несколько старых книг.",
    "Ладно пойдем.",
    "Ну че там, а."
]

n, m = len(book_english), len(book_russian)
MAX_WINDOW = 3              
SIMILARITY_THRESHOLD = 0.4  # Повышаем порог, так как совпадения ниже 0.4 для bge-m3 — это шум

print("Предосчет честных эмбеддингов для всех комбинаций окон...")
def generate_window_embeddings(sentences, max_window):
    window_texts = []
    window_mapping = {}
    for start in range(len(sentences)):
        for step in range(1, max_window + 1):
            if start + step <= len(sentences):
                combined_text = " ".join(sentences[start : start + step])
                window_texts.append(combined_text)
                window_mapping[(start, step)] = len(window_texts) - 1
    embs = model.encode(window_texts, batch_size=64, show_progress_bar=False, convert_to_tensor=True)
    return {key: embs[idx] for key, idx in window_mapping.items()}

en_windows = generate_window_embeddings(book_english, MAX_WINDOW)
ru_windows = generate_window_embeddings(book_russian, MAX_WINDOW)

# Инициализация DP
dp = np.full((n + 1, m + 1), -float('inf'))
dp[0][0] = 0.0

parent = [[None] * (m + 1) for _ in range(n + 1)]

print("Запуск точного выравнивания (DP + Квадратичное усиление)...")
for i in range(n + 1):
    for j in range(m + 1):
        if dp[i][j] == -float('inf'):
            continue
            
        for en_step in range(1, MAX_WINDOW + 1):
            for ru_step in range(1, MAX_WINDOW + 1):
                next_i, next_j = i + en_step, j + ru_step
                
                if next_i <= n and next_j <= m:
                    emb_en = en_windows[(i, en_step)]
                    emb_ru = ru_windows[(j, ru_step)]
                    
                    score = util.cos_sim(emb_en, emb_ru).item()
                    
                    # Если сходство ниже порога, жестко штрафуем этот шаг
                    if score < SIMILARITY_THRESHOLD:
                        current_gain = -2.0
                    else:
                        # ГЛАВНОЕ ИЗМЕНЕНИЕ: возводим чистый скор в квадрат. 
                        # Это поощряет монолитные точные совпадения и обесценивает сумму мелких плохих окон.
                        current_gain = score ** 2
                    
                    if dp[i][j] + current_gain > dp[next_i][next_j]:
                        dp[next_i][next_j] = dp[i][j] + current_gain
                        parent[next_i][next_j] = (i, j, en_step, ru_step)

# Восстановление пути
alignment = []
curr_i, curr_j = n, m

if dp[n][m] == -float('inf'):
    curr_j = int(np.argmax(dp[n]))

while curr_i > 0 and curr_j > 0:
    if parent[curr_i][curr_j] is None:
        prev_i, prev_j, en_step, ru_step = curr_i - 1, curr_j - 1, 1, 1
    else:
        prev_i, prev_j, en_step, ru_step = parent[curr_i][curr_j]
    
    en_matched = book_english[prev_i:curr_i]
    ru_matched = book_russian[prev_j:curr_j]
    
    emb_en = en_windows[(prev_i, en_step)]
    emb_ru = ru_windows[(prev_j, ru_step)]
    link_score = util.cos_sim(emb_en, emb_ru).item()
    
    alignment.append({
        'en_range': f"{prev_i + 1}-{curr_i}",
        'ru_range': f"{prev_j + 1}-{curr_j}",
        'en_text': " ".join(en_matched),
        'ru_text': " ".join(ru_matched),
        'score': link_score,
        'en_step': en_step,
        'ru_step': ru_step
    })
    curr_i, curr_j = prev_i, prev_j

alignment.reverse()

print("\n--- Финальный корректный результат выравнивания ---")
for match in alignment:
    print(f"\nСоответствие (Размер окон: {match['en_step']} к {match['ru_step']})")
    print(f"EN (индексы {match['en_range']}): '{match['en_text']}'")
    print(f"RU (индексы {match['ru_range']}): '{match['ru_text']}'")
    print(f"Сходство (честное): {match['score']:.4f}")