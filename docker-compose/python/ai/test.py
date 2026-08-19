from ai.signatures.text_signature import TextSignature
# ts = TextSignature("/app/models/bge_m3_local")          # load once (~2GB, takes ~30-60s)
# ts = TextSignature("/app/models/minilm")          # load once (~2GB, takes ~30-60s)
ts = TextSignature("/app/models/bge_m3")          # load once (~2GB, takes ~30-60s)
sent_en_1 = "Mystery bores me "
sent_en_2 = "It chores me I know what happens and so do you"
sent_en_3 = f"{sent_en_1} {sent_en_2}"
sent_ru_1 = "Загадочность скучная "
sent_ru_2 = " Я знаю что происходит и вы тоже"
sent_ru_3 = f"{sent_ru_1} И утомляет {sent_ru_2}"
a1 = ts.generate(sent_en_1, "en")
a2 = ts.generate(sent_en_2, "en")
a3 = ts.generate(sent_en_3, "en")
# b = ts.generate("A SMALL ANNOUNCEMENT ABOUT RUDY STEINER", "en")
c2 = ts.generate(sent_ru_1, "ru")
c1 = ts.generate(sent_ru_2, "ru")
c3 = ts.generate(sent_ru_3, "ru")
print(TextSignature.compare(a1, c1))                # similar -> ~0.7+
print(TextSignature.compare(a2, c2))                # similar -> ~0.7+
print(TextSignature.compare(a3, c3))                # similar -> ~0.7+
# print(TextSignature.compare(a2, c3))                # similar -> ~0.7+
print(TextSignature.compare(a1, c3))                # similar -> ~0.7+
# print(TextSignature.compare(b, c))                # similar -> ~0.7+
