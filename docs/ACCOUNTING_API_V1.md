# ITP Inventory — Accounting Integration API v1 (1С, ERP, other ledgers)

Status: **prepared, disabled by default**. Public browser session cookies and Portal/HRM credentials are never accepted as integration authentication. External write endpoints do **not** exist.

## Activation and operational controls

- All endpoints are under \`/inventory-api/api/v1/external/companies/{company_uuid}\`.
- The installation defaults to \`INVENTORY_INTEGRATIONS_ENABLED=false\`; without explicit activation all routes return 404.
- Dedicated tokens are bound to **one company** and one system code (e.g. \`1c\`) and have 64 random characters; only SHA-256 hashes are stored. Secrets appear **once** at creation. Rotate and revoke them via CLI. Use TLS only; send the key in \`Authorization: Bearer inv_...\`, never in query parameters, screenshots, GitHub or a sync log.
- Default abilities: \`assets:read\`, \`references:read\`, \`stock:read\`, \`imports:preview\`. Auth is checked on every request; cross-company access is rejected. Tokens have finite expiry and can be revoked. Request rate limit: 60/minute.
- Create a key on an authorized operator machine: \`php artisan inventory:integration-key COMPANY_UUID --system=1c --name="1C Accounting" --hours=720\`. Record the returned **key ID** for audits. Store the one-time token only inside a managed secret vault at the integration client.
- Revoke by ID: \`php artisan inventory:integration-key COMPANY_UUID --revoke=KEY_UUID\`.
- Keep this feature off until the receiving system, key management, data contract and API rate limits pass acceptance tests. Production DB migrations are a **separate controlled release** with backup and rollback.

## Endpoints

All exports are read-only. Data is always scoped by the key's company ID.

| Method | Endpoint suffix | Purpose | Ability |
| --- | --- | --- | --- |
| GET | \`/metadata\` | Version, fields, import mode, quantity scale | assets:read |
| GET | \`/assets?per_page=100&updated_since=...&updated_until=...&after_id=...\` | UUID-keyed asset delta, including archive tombstones (\`deleted_at\`) | assets:read |
| GET | \`/references\` | Categories, factual/accounting locations, responsible people, facility hierarchy | references:read |
| GET | \`/stock?per_page=100&after_id=...\` | Warehouse/SKU balances, units, versions | stock:read |
| POST | \`/imports/preview\` | Preview name/number matching, duplicates and conflicts; **zero writes** | imports:preview |

### Incremental exports

1. Record a fixed \`updated_until\` ISO-8601 timestamp at the start of a sync.
2. Request \`GET /assets?updated_since=LAST_SUCCESSFUL_CUTOFF&updated_until=CURRENT_CUTOFF&per_page=100\`.
3. Continue with \`meta.next_after_id\`, always retaining the same cutoffs, until it is null. The result includes \`id\` (Inventory UUID), optional \`external_id\`, \`public_id\`, \`inventory_number\`, \`name\`, \`version\`, \`status\`, \`updated_at\` and \`deleted_at\`.
4. Commit the client's watermark **only after all pages and records are processed**. Expect at-least-once deliveries. Deduplicate using company + system + Inventory UUID + version. Treat \`deleted_at\` as a tombstone; never automatically delete ledger entries.
5. Reconcile the initial snapshot and all changes against 1С before enabling any future write flow. Note: this contract does not guarantee snapshot isolation across concurrent updates and is a staging contract pending high-volume verification.

Warehouse quantities are stored as integers in **thousandths** (\`quantity_milli=1250\` means 1.250 units). Currency and financial book value are **not** included in the v1 Inventory export.

### Import preview (no writes)

Request body:
```json
{
  "mode": "dry_run",
  "entity_type": "asset",
  "records": [
    {"external_id": "1c-catalog-guid", "inventory_number": "INV-0001", "name": "Notebook"}
  ]
}
```

Response uses `dry_run: true`, `written: 0` and one decision per row:
- `mapped`: known external-ID mapping and matching asset;
- `manual_match_required`: inventory number exists, but the external link has not been approved;
- `new_candidate`: no existing match; no new record created;
- `conflict`: duplicate external ID/number or incompatible mapping.

Write operations are intentionally not implemented, even with a valid token. A future import needs an approved 1С ↔ Inventory ID mapping table, an idempotency key, change preview, operator approval, transaction + rollback policy, and cross-tenant tests.

## Browser user preferences

`GET|PUT|DELETE /inventory-api/api/v1/companies/{company_uuid}/preferences/{view}` uses regular authenticated Inventory sessions, **not integration bearer keys**. Data belongs to a single user and company. Views: assets, stocktakes, disposals, warehouse_balances, warehouse_items, warehouse_history, people. Accepted fields are whitelisted. Invalid column names, unknown sort keys, foreign-company facility IDs and other unexpected payload keys are rejected.

## Summary and printable reports

- `GET /inventory-api/api/v1/companies/{company_uuid}/reports/summary` returns tenant-filtered assets by status/category/facility/custodian, warehouse balances and low-stock counts, and stocktake totals/discrepancies. Optional `status`, `facility_node_id`, `responsible_person_id` filters apply to asset counts; stock and stocktake metrics are company-wide.
- `GET .../reports/summary.csv` returns an Excel-compatible UTF-8 CSV. Escaping prevents spreadsheet formula injection.
- In the Inventory UI the summary is printable on A4 or saved as PDF via the browser's print dialog. The CSV export uses the same filters.
- Summaries are point-in-time database reads, not signed accounting registers.

## Acceptance before linking a real 1С instance

Confirm the 1С configuration/product (Бухгалтерия / УПП / ERP / УТ), base versions, source catalogs, identifiers (GUID/УИД), exchange direction, mapping ownership, document statuses, time zones, tax and quantity units, and whether background exchange is supported. Validate one company and one synthetic import/export batch first. Require reviewed samples and explicit release approval before activating any production writes.
