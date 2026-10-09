from ai.signatures.text_signature import TextSignature
path = "/app/models/minilm"
path = "/app/models/bge_m3"
path = "/app/models/labse"

print(path)
ts = TextSignature(path)          # load once (~2GB, takes ~30-60s)
sent_en_1 = "But while even the best breathing technique would probably not make much of a difference to our writing, any improvement in the way we organise the everyday writing, how we take notes of what we encounter and what we do with them, will make all the difference for the moment we do face the blank page/screen – or rather not , as those who take smart notes will never have the problem of a blank screen again."
# sent_en_2 = "It chores me I know what happens and so do you"
# sent_en_3 = f"{sent_en_1} {sent_en_2}"
sent_ru_1 = "И хотя даже самая лучшая техника дыхания вряд ли повлияет на то, как мы пишем, любое улучшение организации повседневного письма будет иметь решающее значение в тот момент, когда мы действительно окажемся один на один с пустой страницей или экраном."
sent_ru_2 = "Хотя, скорее всего, не окажемся, поскольку у тех, кто делает полезные заметки, такой проблемы больше не возникнет."
sent_ru_3 = f"{sent_ru_1} И утомляет {sent_ru_2}"
a1 = ts.generate(sent_en_1, "en")
# a2 = ts.generate(sent_en_2, "en")
# a3 = ts.generate(sent_en_3, "en")
# b = ts.generate("A SMALL ANNOUNCEMENT ABOUT RUDY STEINER", "en")
c1 = ts.generate(sent_ru_1, "ru")
c2 = ts.generate(sent_ru_2, "ru")
c3 = ts.generate(sent_ru_3, "ru")
print(TextSignature.compare(a1, c1))                # similar -> ~0.7+
print(TextSignature.compare(a1, c2))                # similar -> ~0.7+
# print(TextSignature.compare(a1, c3))                # similar -> ~0.7+
# print(TextSignature.compare(a2, c3))                # similar -> ~0.7+
print(TextSignature.compare(a1, c3))                # similar -> ~0.7+
# print(TextSignature.compare(b, c))                # similar -> ~0.7+
