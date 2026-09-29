<?php

namespace App\Console\Commands;

use App\Classes\EntityWordAdoption;
use App\Models\Entity;
use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AdoptEntityWordsCommand extends Command
{
    protected $signature = 'words:adopt-from-entities
        {--entity=* : Entity ids to adopt (all entities with word lists when omitted)}
        {--language= : Only entities of this language code}
        {--to= : Comma-separated target language codes for translation fetches (all other enabled languages when omitted)}';

    protected $description = 'Create dictionary words for unmatchable entity tokens and queue translation fetches for words without translations';

    public function handle(EntityWordAdoption $adoption): int
    {
        $entities = $this->entities();

        if ($entities->isEmpty()) {
            $this->info('No entities with word lists to adopt.');

            return self::SUCCESS;
        }

        $targets = $this->targets();

        foreach ($entities as $entity) {
            $stats = $adoption->adoptForEntity($entity, $targets);

            $this->info("Entity #{$entity->id} ({$entity->language?->code}): ".
                "{$stats['adopted']} word(s) adopted into the dictionary, ".
                "{$stats['dispatched']} translation fetch(es) queued.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Entity>
     */
    private function entities(): Collection
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()->with('language:id,code');

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderBy('id')->get();
        }

        if ((string) $this->option('language') !== '') {
            $query->whereHas('language', fn ($query) => $query->where('code', (string) $this->option('language')));
        }

        return $query->whereHas('entityWords')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Language>|null
     */
    private function targets(): ?Collection
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to')))));

        if ($codes === []) {
            return null;
        }

        return Language::query()->whereIn('code', $codes)->orderBy('sort_order')->get();
    }
}
