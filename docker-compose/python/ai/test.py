from splitter import SentenceSplitter


splitter = SentenceSplitter()

splitter.split_file('testen.txt', 'outputen.txt')
splitter.split_file('testru.txt', 'outputru.txt', "ru")





file_path = 'outputru.txt'
# file_path = 'outputen.txt'
with open(file_path, "rt", encoding="utf-8") as f:
    while True:
        line = f.readline()
        if not line:  # Проверка на конец файла
            break
        print(line)