# Per-entity grants require both sides to read a simulator match

Access grants are recorded per Entity (two pivots, `en_entity_user` /
`ru_entity_user`), not per Entity match. Because the bilingual surfaces (the
bilinguals simulator and, with a graceful fallback, the reader) serve the EN
and RU sides of an `EnRuEntityMatch` together, a user may read a match only
when they hold grants on **both** of its Entities. A user who uploads only the
EN side of a work therefore cannot read the bilingual simulator content until
they also upload/match the RU side — a predictable, uniform rule accepted as a
known UX cliff rather than leaking the restricted counterpart.
