<?php

namespace Database\Seeders;

use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class CrosswordDevSeeder extends Seeder
{
    /**
     * Curated content words that actually appear in an English entity (verified
     * against "the book thief"'s entity_words). Order = frequency rank (1 = most
     * common), so all entries sit inside the "Top 100" band. Each row:
     * [word, class slug, definition, forms].
     */
    private const WORDS = [
        ['liesel', 'proper_noun', 'the book thief, a young girl living on Himmel Street in Nazi Germany', []],
        ['rudy', 'proper_noun', 'Liesel\'s best friend, the boy who worships Jesse Owens', []],
        ['max', 'proper_noun', 'the Jewish man who hides from the Nazis in the Hubermanns\' basement', []],
        ['time', 'noun', 'the passing of minutes, hours, and days', ['times']],
        ['back', 'noun', 'the part of the body behind the chest', ['backs']],
        ['book', 'noun', 'written or printed pages bound together for reading', ['books']],
        ['words', 'noun', 'units of speech or writing that carry meaning', ['word']],
        ['basement', 'noun', 'the room below the ground floor of a house', ['basements']],
        ['face', 'noun', 'the front part of the head', ['faces']],
        ['hitler', 'proper_noun', 'the Nazi leader who ruled Germany during the war', []],
        ['tommy', 'proper_noun', 'a neighborhood boy who falls into fainting fits', []],
        ['viktor', 'proper_noun', 'the cruel boy who bullies Liesel after school', []],
        ['first', 'adj', 'coming before all others in order', []],
        ['looked', 'verb', 'directed the eyes toward something', ['look']],
        ['eyes', 'noun', 'the organs of sight', ['eye']],
        ['moment', 'noun', 'a very short period of time', ['moments']],
        ['franz', 'proper_noun', 'one of the boys who mocks Liesel for being unable to read', []],
        ['street', 'noun', 'a public road in a town or city', ['streets']],
        ['something', 'noun', 'an unspecified thing', []],
        ['walked', 'verb', 'moved along on foot', ['walk']],
        ['little', 'adj', 'small in size or amount', []],
        ['hair', 'noun', 'the fine strands growing from the scalp', []],
        ['girl', 'noun', 'a female child', ['girls']],
        ['jew', 'noun', 'a person of Jewish faith or descent', ['jews']],
        ['knew', 'verb', 'was aware of something', ['know']],
        ['stood', 'verb', 'was upright on the feet', ['stand']],
        ['asked', 'verb', 'put a question to someone', ['ask']],
        ['good', 'adj', 'moral, pleasant, or of high quality', []],
        ['left', 'verb', 'went away from a place', ['leave']],
        ['hand', 'noun', 'the body part at the end of the arm', ['hands']],
    ];

    public function run(): void
    {
        $languageId = Language::query()->where('code', 'en')->value('id');

        if ($languageId === null) {
            $this->command?->error('English language is not seeded; run LanguageSeeder first.');

            return;
        }

        foreach (self::WORDS as $rank => [$word, $classSlug, $definition, $forms]) {
            $lWord = mb_strtolower($word);
            $existing = Word::query()
                ->where('language_id', $languageId)
                ->where('l_word', $lWord)
                ->first();

            if ($existing !== null) {
                $wordId = $existing->id;
            } else {
                $wordId = Word::query()->create([
                    'language_id' => $languageId,
                    'word' => $word,
                    'l_word' => $lWord,
                    'frequency' => $rank + 1,
                    'word_class_id' => $this->wordClassId($languageId, $classSlug),
                ])->id;
            }

            Definition::query()->firstOrCreate(
                ['word_id' => $wordId],
                ['definition' => $definition],
            );

            foreach ($forms as $form) {
                Form::query()->firstOrCreate(
                    ['word_id' => $wordId, 'l_word' => mb_strtolower($form)],
                    ['form' => $form],
                );
            }
        }

        Artisan::call('crossword:link');
        $this->command?->info(Artisan::output());
    }

    private function wordClassId(int $languageId, string $slug): int
    {
        return WordClass::query()->firstOrCreate(
            ['language_id' => $languageId, 'slug' => $slug],
            ['title' => ucfirst(str_replace('_', ' ', $slug)), 'description' => ''],
        )->id;
    }
}