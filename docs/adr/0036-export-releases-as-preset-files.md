# 0036. Export releases as preset files

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

[ADR 0028](0028-studio-gamesystem-drafts.md) says "No JSON import or export" and keeps presets file-managed: the console publisher refuses a key a Studio GameSystem owns. That holds while every preset is written by hand. A preset authored in Studio (`preset-pbta`, the first end-to-end proof of M4 on a new system) lives only in the authoring database, so a fresh database never gets it, and `make presets` would fail on the authoring database because Studio owns the key.

## Decision

- **Export is console-only:** `app:gamesystem:export <key> [--release N] [--output path]` writes the content of a published release (the latest by default) as pretty-printed JSON, to the file or to stdout. There is no HTTP endpoint and no import into Studio.
- **The file is the release content exactly as published:** the same canonical document the release stores, so the file hashes the same as the release it came from, and publishing it elsewhere gives an identical release.
- **The console publisher skips the same hash:** for a key a Studio GameSystem owns, `app:gamesystem:publish --if-changed` publishes nothing and exits 0 when the latest release has the file's content hash (`Unchanged <key> (vN, managed in Studio)`). A file with a different hash, or any run without `--if-changed`, is still refused as ADR 0028 decided.
- **A database without that Studio draft is unchanged:** the file publishes as a normal file-managed preset.
- **Publishing from Studio is untouched:** the HTTP publish path and the draft model do not change.

## Consequences

- The Studio draft stays the source of truth on the authoring database. After each Studio publish, export again and commit the file.
- `make presets` keeps working on the authoring database and seeds fresh databases from the committed file.
- A fresh database's copy is file-managed and cannot be edited in Studio, as ADR 0028 says; a manager copies it under a new key.
- The skip compares content only: a Studio release newer than the file with other content makes `make presets` fail until the file is exported again, which is the intended warning.
