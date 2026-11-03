# API reference

## Primary sync endpoint

POST /api/tasks/sync accepts a row and lets SheetCollab choose create, update, or skip. The caller cannot choose a task ID or force an action.

### Authentication

Send an individual integration token:

    Authorization: Bearer sc_<64 lowercase hexadecimal characters>

Each token belongs to one integration and can be revoked independently. The ActiveCollab API token is never sent by the spreadsheet script.

Legacy shared-token access is limited to the compatibility routes below. It requires ACTIVECOLLAB_ALLOW_LEGACY_TOKEN=true and the integration allowlist. Prefer individual tokens.

### Request

Content-Type must be application/json. Requests are limited to 64 KiB, must be a JSON object, and reject unknown fields.

| Field | Required | Format |
|---|---|---|
| record_uuid | Yes | UUID |
| spreadsheet_id | Yes | String, up to 255 characters; a Google Sheets URL is also accepted |
| sheet_id | Yes | String, up to 64 characters |
| row_number | Yes | Integer, 1–10,000,000 |
| environment | Yes | String, up to 100 characters |
| section | Yes | String, up to 255 characters |
| description | Yes | String, up to 16,000 characters |
| severity | No | Nullable string, up to 100 characters |
| priority | No | Nullable string, up to 100 characters |
| device, resolution | No | Nullable strings, up to 255 characters |
| screenshot | No | Nullable HTTP(S) URL, up to 2,048 characters |
| comment | No | Nullable string, up to 8,000 characters |
| task_type, status | No | Nullable strings, up to 100 characters |
| responsible, reporter | No | Nullable strings, up to 255 characters |

Example:

    {
      "record_uuid": "9b704b78-a50f-4d5d-a62a-b4a5e173aa81",
      "spreadsheet_id": "1r9uFA2w0_pd_ciYKmyE50qeXiiUkDVPsV6kCByjZVj4",
      "sheet_id": "0",
      "row_number": 8,
      "environment": "stage",
      "section": "Catalog",
      "description": "Product page does not load",
      "severity": "major"
    }

### Success response

HTTP 201 means a task was created. HTTP 200 means it was updated or skipped.

    {
      "data": {
        "record_uuid": "9b704b78-a50f-4d5d-a62a-b4a5e173aa81",
        "action": "created",
        "task_id": 123,
        "task_url": "https://ac.example.test/projects/10/tasks/123",
        "synced_at": "2026-11-03T10:00:00+00:00",
        "sync_hash": "sha256...",
        "operation_id": "operation-uuid"
      }
    }

For a skipped row, action is skipped and attempted_at records the current check; synced_at remains the last confirmed sync time.

### Errors and retry behavior

| HTTP | Code or cause | Client behavior |
|---|---|---|
| 401 | unauthorized | Check or rotate the integration token |
| 403 | forbidden | Verify the token is scoped to this spreadsheet |
| 404 | No matching integration | Check the integration and spreadsheet ID |
| 409 | sync_in_progress, legacy_binding_conflict, remote_task_missing, or unresolved state | Do not automatically retry; inspect the error code |
| 413 | request_too_large | Reduce the JSON body below 64 KiB |
| 422 | invalid JSON or validation error | Fix the request before retrying |
| 502 / 504 | External result could not be confirmed | Reconcile through Orchid before another create |

Structured sync errors include error.code, error.operation_id, and error.retryable. A timeout or uncertain result must not be treated as a safe retry. An already-running operation returns 409 and does not start a second ActiveCollab request.

## Compatibility routes

| Method and path | Purpose |
|---|---|
| POST /api/integrations/activecollab/tasks | Legacy create |
| PUT /api/integrations/activecollab/tasks/{taskId} | Legacy update |
| GET /api/integrations/activecollab/task-lists | Read task lists |
| GET /api/integrations/activecollab/users | Read project users |
| GET /api/integrations/activecollab/labels | Read project labels |

These routes support individual tokens. Shared-token access is temporary compatibility and can be disabled globally and per integration. Details for token rollout and revocation are in [Deployment](DEPLOY.md#token-rollout).

All ActiveCollab HTTP requests pass through the server repository. Do not put ActiveCollab credentials in client requests.
