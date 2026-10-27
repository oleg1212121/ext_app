/**
 * The word popup's usage-label display tiers (popup-usage-labels spec):
 * the closed six labels split into two display tiers. The dimming tier
 * (obsolete, archaic, rare, dialectal) renders vermilion chips and dims
 * to half opacity — any dimming label present dims, even beside muted
 * ones. The register tier (dated, colloquial) renders a muted chip and
 * never dims. The tiers serve two renderers: a definition sense (chips +
 * the line's opacity, from `definitions[].labels`) and a whole part-of-
 * speech section (chips + the section's opacity — the marked entry dims
 * whole, hover restoring full opacity — from the entry's word-level
 * `labels`, the source's own headword mark).
 *
 * The split is deliberately NOT the completeness DEMOTING subset
 * (obsolete/archaic only, App\Support\UsageLabels::DEMOTING): rare and
 * dialectal dim on display while completeness still scores them live. The
 * tier sets live here, JS-side, and must not touch the PHP constants.
 */

// The dimming tier: a sense carrying any of these renders at half opacity.
export const DIMMING_LABELS = ['obsolete', 'archaic', 'rare', 'dialectal'];

// The register tier: muted chip, no dimming.
export const REGISTER_LABELS = ['dated', 'colloquial'];

export const isDimmingLabel = (slug) => DIMMING_LABELS.includes(slug);

// The closed-set statement both renderers share: the popup's chips and the
// Anki note's plain-text label prefix (lib/ankiTemplate) drop the same
// unknown strays — a slug outside the two tiers has no word.label_* string.
export const isKnownLabel = (slug) => isDimmingLabel(slug) || REGISTER_LABELS.includes(slug);

// A sense dims when any of its labels sits in the dimming tier; a null
// column (unlabelled) and the empty list both render undimmed.
export const dimsDefinition = (labels) => (labels ?? []).some(isDimmingLabel);

// One chip per known label, in stored order, resolved through the app's
// word.label_* ui-strings (t injected). Unknown
// slugs drop — the set is closed; this is belt-and-braces against a stray
// slug rendering as a raw ui-string key.
export const usageLabelChips = (labels, t) => (labels ?? [])
    .filter(isKnownLabel)
    .map((slug) => ({text: t(`word.label_${slug}`), dimming: isDimmingLabel(slug)}));

// A part-of-speech section dims — whole, hover restoring full opacity —
// when the WORD itself carries a dimming-tier mark: the source's headword
// label ("(obsolete)" under the part-of-speech heading), imported onto the
// words row and shipped as entry.labels. Never derived from the senses: an
// entry whose every definition happens to be labelled keeps an unmarked,
// undimmed section.
export const dimsEntry = (entry) => (entry?.labels ?? []).some(isDimmingLabel);

// The header's chips: the word-level labels through the same closed-set
// chip renderer — a headword mark could carry a register label ("dated")
// beside or instead of a dimming one.
export const entryUsageChips = (entry, t) => usageLabelChips(entry?.labels ?? [], t);
