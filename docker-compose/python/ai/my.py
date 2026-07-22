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

ru_combinations = []
cur = ""
for s in book_russian:
    cur += f"{s} "
    ru_combinations.append([cur, model.encode(cur)])


en_combinations = []
cur = ""
for s in book_english:
    cur += f"{s} "
    en_combinations.append([cur, model.encode(cur)])

n, m = len(en_combinations), len(ru_combinations)

for i in range(n+1):
    for j in range(m+1):
        score = util.cos_sim(emb_en, emb_ru).item()
