# Works are a public catalog

ADR 0013 made uploads restricted-by-default with per-entity grants and deliberately gave entities no creator tracking. Exposing works directly in the user-facing Library (which replaced the language-first `/entities` browse pages) forced a choice: either works gain access semantics — a `created_by`, a visibility flag, or "a work only exists once it has a first entity" — or a work stays what the schema already says it is: bibliographic metadata about a book/source, carrying no readable content of its own.

We decided works carry no access semantics: every approved user sees every work, including works with no entities (an empty work shows its info and the add-entity entry only). Content and access control remain entirely on entities — every entity list or count shown to a user stays scoped to what they can read, so a work card never reveals how many restricted entities it hides (Readable count).

Rejected alternatives: `created_by` on works (reintroduces the creator tracking 0013 avoided, and only the empty-work state needs it) and requiring a first entity at work creation (keeps a user-side "every work has an entity" invariant but makes "create a work" secretly mean "upload a text"). Consequence: the catalog is open to drafts from any approved user — accepted, since an empty or fully-restricted work exposes nothing readable.
