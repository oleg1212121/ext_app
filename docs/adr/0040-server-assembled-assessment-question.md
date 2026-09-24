# Server-assembled assessment question from DB prompt templates

The simulator's assessment question used to be one user-editable string:
a controller constant shipped to the page, the client substituted the base
language name, and the whole text came back on every ask to serve as the AI
system message. We split it. The format-rules half became an admin-editable
`prompt_templates` row (key `simulator.question.format`, placeholders
`:base`/`:learning`, edited via a Filament "Prompt Templates" resource), the
task-list half stays user-editable per user in UI settings, and the
`/ai/question*` endpoints now receive only `tasks` plus the two current
column language codes — the server reads the template, substitutes, and
joins (`App\Support\PromptTemplates::assemble`). Server-side assembly keeps
the client from bypassing admin prompt changes and lets the template track
the language toggle even when the user never edits anything (the old
uncontrolled textarea froze its initial text on first keystroke). The
user's stored question now holds only their task-list customization; no
legacy migration was made (single-user deployment — the saved full text is
treated as a verbatim task-list edit). Supersedes the constant-based
question template described in ADR 0037. The word popup's Context
explanation instruction moved into the same table (`word.explanation`,
placeholders `:word`/`:native`) for the same reason — it was the last
hardcoded AI prompt on the reading surfaces.
