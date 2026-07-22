from sentence_transformers import SentenceTransformer
model = SentenceTransformer('BAAI/bge-m3')
# Сохраняем все веса модели прямо в текущую директорию в папку 'bge_m3_local'
model.save('bge_m3_local')

print("Модель успешно сохранена локально!")
