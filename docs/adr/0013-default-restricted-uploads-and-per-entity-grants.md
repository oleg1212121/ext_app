# Default-restricted uploads with per-entity access grants

Every uploaded Entity now defaults to `is_restricted = true` (readable only by
admin and explicitly granted users). The uploader receives an Access grant on
their own Entity. When an upload's Signature cosine-matches an existing Entity
at ≥0.95, no new Entity is created — the uploader is granted access to the
existing one instead. Admin publishes an Entity (`is_restricted = false`) to
make it readable by all approved users. There is intentionally no `created_by`
column: the uploader's access is a grant row like any other, and first-uploader
vs signature-match grants are distinguished only by a null vs non-null
`similarity`.
