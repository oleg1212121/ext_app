/**
 * The word popup's usage-label display tiers (popup-usage-labels spec):
 * the closed six labels a definition sense can carry split into two display
 * tiers. The dimming tier (obsolete, archaic, rare, dialectal) renders
 * vermilion chips and dims the whole sense line to half opacity — any
 * dimming label present dims, even beside muted ones. The register tier
 * (dated, colloquial) renders a muted chip and never dims.
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

// A sense dims when any of its labels sits in the dimming tier; a null
// column (unlabelled) and the empty list both render undimmed.
export const dimsDefinition = (labels) => (labels ?? []).some(isDimmingLabel);

// One chip per known label, in stored order, resolved through the app's
// word.label_* ui-strings (t injected like lib/languageLabel). Unknown
// slugs drop — the set is closed; this is belt-and-braces against a stray
// slug rendering as a raw ui-string key.
export const usageLabelChips = (labels, t) => (labels ?? [])
    .filter((slug) => isDimmingLabel(slug) || REGISTER_LABELS.includes(slug))
    .map((slug) => ({text: t(`word.label_${slug}`), dimming: isDimmingLabel(slug)}));
