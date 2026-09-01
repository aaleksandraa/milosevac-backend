# M2 — Pilot settlement import

Implemented. Full write-up: frontend `docs/settlement-atlas/M2_PILOT_SETTLEMENT_IMPORT.md`.

- Fixture: `database/imports/atlas/census-2013-pilot-settlements.json`
- Command: `php artisan atlas:import-settlements --pilot`
- 24 Modriča + 21 Šamac settlements, 2013 census population rows
- Idempotent second run: 0 inserted / 0 updated
