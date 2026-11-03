# User guide

SheetCollab links a Google spreadsheet to one ActiveCollab project through the Orchid admin panel. The in-app **Instruction** page at /admin/guide is the current setup walkthrough.

## Before you start

You need an active SheetCollab account, a public HTTPS address for the service, an ActiveCollab API token with access to the target project, and edit access to the Google spreadsheet.

Google Apps Script cannot connect to localhost. For local development, expose the app through a trusted HTTPS tunnel.

## Configure the connection

1. In Orchid, open **Connections** and add the ActiveCollab base URL and API token. Use **Check connection** to verify access.
2. Open **Integrations**, create an integration, and provide the spreadsheet URL, connection, project ID, and an existing task list if needed.
3. Configure optional task-list, assignee, and label mappings. Mapping keys must match the values in the spreadsheet.
4. An administrator assigns the integration to its users and issues an individual SheetCollab token.

The ActiveCollab token remains encrypted in the connection. The individual SheetCollab token is shown only when issued; store it in Script Properties and do not include it in documents or logs.

## Install Apps Script

1. In Orchid, download the current Apps Script.
2. In the spreadsheet, open **Extensions → Apps Script**, replace Code.gs with the downloaded file, and save.
3. In **Project Settings → Script Properties**, set:
   - INTEGRATION_SERVICE_URL — public HTTPS base URL of SheetCollab, without /admin or /api.
   - INTEGRATION_SERVICE_TOKEN — individual token for this integration.
4. Refresh the spreadsheet.

The script finds the header row by the required “Серьезность” header in the first 10 rows. Required row fields are “Стенд”, “Раздел”, “Описание проблемы”, and “Ссылка на задачу”. Optional fields include severity, task type, responsible person, priority, device, resolution, screenshot, comment, reporter, and status.

The script adds a hidden “SheetCollab UUID” column. Before first sync, an administrator must complete the [spreadsheet identity migration](DEPLOY.md#migrate-an-existing-spreadsheet).

## Sync rows safely

Select a row and choose **ActiveCollab → Sync task**. SheetCollab uses the integration and row UUID to decide whether to create, update, or skip. It does not treat a task ID copied from a cell as proof of ownership.

Always sort and move complete rows, including the hidden UUID column. Do not edit UUIDs. A copied row keeps the original UUID; choose **New record from copy** to assign a new identity to the copy.

If a request times out or its result is unknown, do not retry it as a new create. Open **Export history** and ask an administrator to reconcile the record. Unknown outcomes can otherwise create duplicate tasks.

## Access

| Role | Access |
|---|---|
| Admin | All connections, integrations, users, roles, tokens, and recovery actions |
| Manager | View and edit mappings for assigned integrations |
| Viewer | View assigned integrations and their history |

A manager or viewer needs both the Orchid permission and an explicit integration assignment. Only admins manage connections, destinations, memberships, tokens, and recovery.

## Troubleshooting

- Header not found: add the exact “Серьезность” header within the first 10 rows.
- 401: check the individual token in Script Properties and confirm it has not been revoked.
- Integration not found: verify the spreadsheet URL and integration status.
- Mapping error: compare the cell value with the configured mapping key.
- ActiveCollab 403/404: check the connection URL, token, project access, and existing task list.
- Menu missing: save Apps Script and refresh the spreadsheet.
- Unknown result or missing task: use Export history and request an administrator review; do not edit database records to retry.
