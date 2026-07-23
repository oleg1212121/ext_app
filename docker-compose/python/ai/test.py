from splitter import SentenceSplitter
from my import BilingualAligner

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

aligner = BilingualAligner("./bge_m3_local")
aligner.process("outputen.txt", "outputru.txt", "matches_output.txt")
print("Results written to matches_output.txt")
