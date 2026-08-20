from sentence_transformers import SentenceTransformer
model = SentenceTransformer('sentence-transformers/LaBSE')
# Сохраняем все веса модели прямо в текущую директорию в папку 'labse_local'
model.save('labse_local')

print("Модель успешно сохранена локально!")
