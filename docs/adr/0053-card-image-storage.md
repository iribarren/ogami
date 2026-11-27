# 0053. Card images stored in PostgreSQL, pinned by releases

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Feature `studio-print-cards` (roadmap B36, [#427](https://github.com/iribarren/ogami/issues/427)) prints a published release's flow as cards. A GameSystem may carry two images for them: a card back and a front frame. The app stores no binary data yet, has no upload code, and documents no production topology. The only backup path is `pg_dump` through `make db-restore`.

A release is immutable ([ADR 0010](0010-versioned-gamesystem-releases.md)) and its content is exported as a preset file ([ADR 0036](0036-export-releases-as-preset-files.md)); a later upload must never change what a published release prints.

## Decision

- **PostgreSQL `bytea`, content-addressed.** Images live in a table keyed by the sha256 of the stored bytes, behind a Studio `ImageStore` port. They travel with `pg_dump` and `make db-restore`. An object store can replace the adapter later without touching the callers.
- **A release pins images beside its content.** The pins sit in a table of their own, `studio_release_card_image_pin (release_id, slot, sha256)`, with a foreign key to the release and one to the stored image, so the database guarantees a pinned image exists. They are written once, in the same transaction as the release, copied from the draft's pins, and never changed. The content hash and the preset export do not change, and a changed, added or removed image counts as a change when publishing (409 `no_changes` only when the content hash and the pins both equal the latest release's); the console publisher pins nothing. A release published before images existed has no pin rows and reads as having no images. A draft keeps its own pins, one per slot, in a table apart from the draft document, so an upload never bumps the draft's version. Restoring a release into the draft also restores its pins: a slot the release left empty is emptied, and images nobody pins any more are deleted. The owner reads an image pinned by the draft or by any release of the GameSystem.
- **Two slots, PNG or JPEG, re-encoded.** Slots are `cardBack` and `cardFront` (a frame). The server decodes each upload with GD and re-encodes it (PNG keeps its alpha, JPEG at quality 92), which drops metadata and neutralises polyglot files. SVG, GIF, WebP and anything else is refused with 422 and a reason code.
- **Limits.** The upload is at most 5 MB (`upload_max_filesize` 5M, `post_max_size` 8M). The pixel size is read from the header before decoding: 408 × 555 px up to 1630 × 2220 px, aspect ratio within 2 % of 69:94. The recommended size is 815 × 1110 px, which is 69 × 94 mm at 300 dpi (a 63 × 88 mm card plus 3 mm bleed). The front's text sits in a fixed 5 mm safe area inside the trim.
- **Access.** Only the owning game manager uploads, replaces, removes and reads images; anyone else gets 404 ([ADR 0028](0028-studio-gamesystem-drafts.md)). A pinned image is readable only through a GameSystem or release its owner owns. Responses carry the stored `Content-Type`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` and an immutable cache, because the URL holds the hash. Play gets its own read path with B38.

## Alternatives

| Option | Why not |
|---|---|
| Filesystem volume | Escapes the `pg_dump` backup and `db-restore`, and needs a shared volume in every environment |
| Object store (S3, MinIO) | A new service with credentials, and no production topology is documented to place it in |
| Image inside the hashed content | Breaks the ADR 0036 round trip across databases, and bytes do not belong in the canonical JSON |
| Keeping the original bytes | Metadata such as EXIF GPS leaks, and polyglot files stay dangerous |

## Consequences

- Backups grow with the images; at most two images of up to a few MB per GameSystem, plus the ones releases keep.
- Re-encoding costs memory: the pixel limits are checked from the header before decoding, so a decompression bomb is refused.
- A blob is removed when no draft or release pins it, which the store checks.
- The PHP image needs the `gd` extension.
