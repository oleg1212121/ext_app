from pathlib import Path

from sentence_transformers import SentenceTransformer

from ai.alignment.bilingual_aligner import BilingualAligner
from ai.signatures.text_signature import TextSignature
from ai.splitting.sentence_splitter import SentenceSplitter

BASE_DIR = Path(__file__).parent


def main() -> None:
    en_file = BASE_DIR / "testen.txt"
    ru_file = BASE_DIR / "testru.txt"
    en_output = BASE_DIR / "outputen.txt"
    ru_output = BASE_DIR / "outputru.txt"
    matches_output = BASE_DIR / "matches_output.txt"

    # 1. Split raw texts into sentences (must run before anything reads the outputs)
    splitter = SentenceSplitter()
    en_count = splitter.split_file(en_file, en_output, "en")
    ru_count = splitter.split_file(ru_file, ru_output, "ru")
    print(f"Sentences: EN={en_count}, RU={ru_count}")

    # 2. Load the model once and share it between the classes that need it
    model = SentenceTransformer(str(BASE_DIR / "bge_m3_local"))
    signature_maker = TextSignature(model=model)
    aligner = BilingualAligner(model=model)

    # 3. Text signatures: raw vs split versions of the same text should be close
    en_raw_sig = signature_maker.generate_from_file(en_file)
    en_split_sig = signature_maker.generate_from_file(en_output)
    ru_raw_sig = signature_maker.generate_from_file(ru_file, language="ru")
    ru_split_sig = signature_maker.generate_from_file(ru_output, language="ru")

    print(f"EN raw vs EN split: {TextSignature.compare(en_raw_sig, en_split_sig):.4f}")
    print(f"RU raw vs EN split: {TextSignature.compare(ru_raw_sig, en_split_sig):.4f}")
    print(f"RU raw vs RU split: {TextSignature.compare(ru_raw_sig, ru_split_sig):.4f}")

    # 4. Align sentences between languages
    aligner.process(en_output, ru_output, matches_output)
    print("Results written to matches_output.txt")

    # 5. Show the split RU sentences
    with open(ru_output, "rt", encoding="utf-8") as f:
        for line in f:
            print(line, end="")


if __name__ == "__main__":
    main()
