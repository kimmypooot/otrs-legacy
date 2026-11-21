# OTRS — Legacy Snapshot

This repository is a **legacy snapshot** of the Online Training Registration
System (OTRS), a subsystem of the IMIS platform for CSC Regional Office VIII.
It is kept here for reference and archival purposes — it is **not** the
actively developed codebase.

## What this is

OTRS manages training programs, participant registration, payment tracking,
certificate generation, and post-training evaluations. It is one of several
independent subsystems hosted under the IMIS launcher (`imis/otrs/` in the
live deployment), which depends on shared infrastructure (session handling,
database connection, layout partials) living outside this repository.

Because this snapshot was extracted from that larger deployment, it will not
run standalone without the parent `imis/` directory (`includes/`,
`imis_include.php`, etc.) alongside it.

## What's excluded

Uploaded and generated files are excluded via `.gitignore` and were never
committed:

- `assets/uploads/` — certificates, QR codes, SME photos
- `hrd/certificates/`
- `.env` files and logs

## Status

No active development happens in this repository. See the live IMIS
monorepo for the current state of OTRS.
