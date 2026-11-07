---
title: Backups and recovery drills
section: Operations
order: 50
---
- **Backups** run nightly (01:30), encrypted with `BACKUP_PASSPHRASE`; a **restore test** runs every Sunday.
- **Recovery drill** (System health > Run a drill, and automatically on the 1st of each month): takes a fresh backup, restores it into a scratch database on the same local server and compares row counts, checks the replica receives a new write (if `replica` is configured), and checks each peer server in `PLATFORM_DR_PEERS`. It records the **recovery time** and how much data could be lost.
- Run a drill before every exam season and after any server change. The full failover steps are in `docs/DISASTER-RECOVERY.md`.
