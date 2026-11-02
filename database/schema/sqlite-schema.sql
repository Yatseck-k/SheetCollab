CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "permissions" text
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "slug" varchar not null,
  "name" varchar not null,
  "permissions" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "roles_slug_unique" on "roles"("slug");
CREATE TABLE IF NOT EXISTS "role_users"(
  "user_id" integer not null,
  "role_id" integer not null,
  foreign key("user_id") references "users"("id") on delete cascade on update cascade,
  foreign key("role_id") references "roles"("id") on delete cascade on update cascade,
  primary key("user_id", "role_id")
);
CREATE TABLE IF NOT EXISTS "attachments"(
  "id" integer primary key autoincrement not null,
  "name" text not null,
  "original_name" text not null,
  "mime" varchar not null,
  "extension" varchar,
  "size" integer not null default '0',
  "sort" integer not null default '0',
  "path" text not null,
  "description" text,
  "alt" text,
  "hash" text,
  "disk" varchar not null default 'public',
  "user_id" integer,
  "group" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "attachmentable"(
  "id" integer primary key autoincrement not null,
  "attachmentable_type" varchar not null,
  "attachmentable_id" integer not null,
  "attachment_id" integer not null,
  foreign key("attachment_id") references "attachments"("id") on delete cascade on update cascade
);
CREATE INDEX "attachmentable_attachmentable_type_attachmentable_id_index" on "attachmentable"(
  "attachmentable_type",
  "attachmentable_id"
);
CREATE TABLE IF NOT EXISTS "notifications"(
  "id" varchar not null,
  "type" varchar not null,
  "notifiable_type" varchar not null,
  "notifiable_id" integer not null,
  "data" text not null,
  "read_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "notifications_notifiable_type_notifiable_id_index" on "notifications"(
  "notifiable_type",
  "notifiable_id"
);
CREATE TABLE IF NOT EXISTS "active_collab_task_exports"(
  "id" varchar not null,
  "integration_id" varchar,
  "external_id" varchar not null,
  "spreadsheet_id" varchar not null,
  "sheet_id" varchar not null,
  "row_number" integer not null,
  "project_id" integer not null,
  "task_id" integer,
  "task_url" text,
  "source_payload" text not null,
  "active_collab_payload" text,
  "active_collab_response" text,
  "sync_hash" varchar,
  "status" varchar not null default 'pending',
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "last_attempt_status" varchar,
  "binding_status" varchar,
  "operation_id" varchar,
  "lease_expires_at" datetime,
  "synced_at" datetime,
  "record_uuid" varchar,
  "hash_version" varchar,
  primary key("id")
);
CREATE INDEX "active_collab_task_exports_integration_id_index" on "active_collab_task_exports"(
  "integration_id"
);
CREATE TABLE IF NOT EXISTS "script_assets"(
  "id" varchar not null,
  "script_key" varchar not null,
  "name" varchar not null,
  "content" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "script_assets_script_key_unique" on "script_assets"(
  "script_key"
);
CREATE INDEX "ac_exports_integration_task_index" on "active_collab_task_exports"(
  "integration_id",
  "task_id"
);
CREATE TABLE IF NOT EXISTS "active_collab_sync_attempts"(
  "id" varchar not null,
  "export_id" varchar not null,
  "integration_id" varchar not null,
  "operation_id" varchar not null,
  "action" varchar not null,
  "status" varchar not null,
  "started_at" datetime not null,
  "finished_at" datetime,
  "http_status" integer,
  "error_code" varchar,
  "error_message" text,
  "source_payload" text not null,
  "active_collab_payload" text,
  "active_collab_response" text,
  "sync_hash" varchar,
  "hash_version" varchar,
  foreign key("export_id") references "active_collab_task_exports"("id") on delete restrict,
  primary key("id")
);
CREATE INDEX "active_collab_sync_attempts_integration_id_index" on "active_collab_sync_attempts"(
  "integration_id"
);
CREATE UNIQUE INDEX "active_collab_sync_attempts_operation_id_unique" on "active_collab_sync_attempts"(
  "operation_id"
);
CREATE TABLE IF NOT EXISTS "admin_audit_logs"(
  "id" varchar not null,
  "actor_id" integer,
  "subject_type" varchar not null,
  "subject_id" varchar not null,
  "integration_id" varchar,
  "connection_id" varchar,
  "action" varchar not null,
  "request_id" varchar not null,
  "operation_id" varchar not null,
  "phase" varchar not null,
  "before" text not null,
  "after" text not null,
  "context" text not null,
  "created_at" datetime not null,
  foreign key("actor_id") references "users"("id") on delete set null,
  primary key("id")
);
CREATE UNIQUE INDEX "admin_audit_logs_request_id_phase_unique" on "admin_audit_logs"(
  "request_id",
  "phase"
);
CREATE INDEX "admin_audit_logs_subject_id_index" on "admin_audit_logs"(
  "subject_id"
);
CREATE INDEX "admin_audit_logs_integration_id_index" on "admin_audit_logs"(
  "integration_id"
);
CREATE INDEX "admin_audit_logs_operation_id_index" on "admin_audit_logs"(
  "operation_id"
);
CREATE UNIQUE INDEX "ac_exports_integration_record_unique" on "active_collab_task_exports"(
  "integration_id",
  "record_uuid"
);
CREATE INDEX "active_collab_task_exports_external_id_index" on "active_collab_task_exports"(
  "external_id"
);
CREATE TABLE IF NOT EXISTS "active_collab_integration_tokens"(
  "id" varchar not null,
  "integration_id" varchar not null,
  "name" varchar not null,
  "token_hash" varchar not null,
  "created_at" datetime not null,
  "revoked_at" datetime,
  "last_used_at" datetime,
  foreign key("integration_id") references "active_collab_integrations"("id") on delete restrict,
  primary key("id")
);
CREATE UNIQUE INDEX "active_collab_integration_tokens_token_hash_unique" on "active_collab_integration_tokens"(
  "token_hash"
);
CREATE TABLE IF NOT EXISTS "active_collab_connections"(
  "id" varchar not null,
  "name" varchar not null,
  "base_url" varchar not null,
  "api_token" text not null,
  "active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "active_collab_integrations"(
  "id" varchar not null,
  "name" varchar not null,
  "spreadsheet_id" varchar not null,
  "active_collab_base_url" varchar,
  "active_collab_project_id" integer not null,
  "active_collab_task_list_id" integer not null,
  "active_collab_api_token" text,
  "task_list_mapping" text,
  "assignee_mapping" text,
  "label_mapping" text,
  "active" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "uuid_only" tinyint(1) not null default('0'),
  "allow_legacy_token" tinyint(1) not null default('0'),
  "connection_id" varchar,
  foreign key("connection_id") references "active_collab_connections"("id") on delete restrict,
  primary key("id")
);
CREATE UNIQUE INDEX "active_collab_integrations_spreadsheet_id_unique" on "active_collab_integrations"(
  "spreadsheet_id"
);
CREATE TABLE IF NOT EXISTS "active_collab_integration_user"(
  "integration_id" varchar not null,
  "user_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("integration_id") references "active_collab_integrations"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  primary key("integration_id", "user_id")
);
CREATE INDEX "active_collab_integration_user_user_id_integration_id_index" on "active_collab_integration_user"(
  "user_id",
  "integration_id"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2015_04_12_000000_create_orchid_users_table',1);
INSERT INTO migrations VALUES(5,'2015_10_19_214424_create_orchid_roles_table',1);
INSERT INTO migrations VALUES(6,'2015_10_19_214425_create_orchid_role_users_table',1);
INSERT INTO migrations VALUES(7,'2016_08_07_125128_create_orchid_attachmentstable_table',1);
INSERT INTO migrations VALUES(8,'2017_09_17_125801_create_notifications_table',1);
INSERT INTO migrations VALUES(9,'2026_06_24_000001_create_active_collab_task_exports_table',1);
INSERT INTO migrations VALUES(10,'2026_06_24_000002_create_active_collab_integrations_table',1);
INSERT INTO migrations VALUES(11,'2026_06_28_000001_create_script_assets_table',1);
INSERT INTO migrations VALUES(12,'2026_06_28_000002_rename_legacy_script_asset_key',1);
INSERT INTO migrations VALUES(13,'2026_06_28_000003_drop_active_collab_instance_url_from_integrations',1);
INSERT INTO migrations VALUES(14,'2026_07_05_141657_make_active_collab_api_token_nullable',1);
INSERT INTO migrations VALUES(15,'2026_10_29_000001_add_operation_state_to_active_collab_task_exports_table',1);
INSERT INTO migrations VALUES(16,'2026_10_29_000002_create_active_collab_sync_attempts_table',1);
INSERT INTO migrations VALUES(17,'2026_10_30_000001_create_admin_audit_logs_table',1);
INSERT INTO migrations VALUES(18,'2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations',1);
INSERT INTO migrations VALUES(19,'2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports',1);
INSERT INTO migrations VALUES(20,'2026_10_30_000004_add_payload_hash_version_and_integration_tokens',1);
INSERT INTO migrations VALUES(21,'2026_11_02_000001_create_active_collab_connections_and_add_connection_id',1);
INSERT INTO migrations VALUES(22,'2026_11_02_000002_create_active_collab_integration_user_table',1);
