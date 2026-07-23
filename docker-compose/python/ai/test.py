from splitter import SentenceSplitter
from my import BilingualAligner
from Signature import TextSignature

signatureMaker = TextSignature()
splitter = SentenceSplitter()
enfile = 'testen.txt'
enfile_output = 'outputen.txt'

rufile = 'testru.txt'
rufile_output = 'outputru.txt'
first = signatureMaker.generate_from_file(enfile)
second = signatureMaker.generate_from_file(enfile_output)
third = signatureMaker.generate_from_file(rufile)
fourth = signatureMaker.generate_from_file(rufile_output)
print(signatureMaker.compare(first, second))
print(signatureMaker.compare(third, second))
print(signatureMaker.compare(third, fourth))
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

# aligner = BilingualAligner("./bge_m3_local")
# aligner.process("outputen.txt", "outputru.txt", "matches_output.txt")
# print("Results written to matches_output.txt")
