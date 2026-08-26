-- ---------------------------------------------------------------------
-- PharmaVerify - Microsoft SQL Server schema
--
-- Generated from the Laravel migrations, which remain the source of
-- truth. This script exists so the schema can be reviewed and applied
-- by a DBA where `php artisan migrate` is not run directly.
--
-- Regenerate with:  php artisan pharmaverify:sqlsrv-schema
-- ---------------------------------------------------------------------


create table "users" ("id" bigint not null identity primary key, "name" nvarchar(150) not null, "email" nvarchar(150) not null, "email_verified_at" datetime null, "password" nvarchar(191) not null, "employee_code" nvarchar(50) null, "phone" nvarchar(30) null, "status" nvarchar(20) not null default 'active', "last_login_at" datetime null, "remember_token" nvarchar(100) null, "created_at" datetime null, "updated_at" datetime null);

create index "users_status_index" on "users" ("status");

create unique index "users_email_unique" on "users" ("email");

create table "password_reset_tokens" ("email" nvarchar(150) not null, "token" nvarchar(191) not null, "created_at" datetime null);

alter table "password_reset_tokens" add constraint "password_reset_tokens_email_primary" primary key ("email");

create table "sessions" ("id" nvarchar(191) not null, "user_id" bigint null, "ip_address" nvarchar(45) null, "user_agent" nvarchar(max) null, "payload" nvarchar(max) not null, "last_activity" int not null);

alter table "sessions" add constraint "sessions_id_primary" primary key ("id");

create index "sessions_user_id_index" on "sessions" ("user_id");

create index "sessions_last_activity_index" on "sessions" ("last_activity");

create table "cache" ("key" nvarchar(191) not null, "value" nvarchar(max) not null, "expiration" int not null);

alter table "cache" add constraint "cache_key_primary" primary key ("key");

create index "cache_expiration_index" on "cache" ("expiration");

create table "cache_locks" ("key" nvarchar(191) not null, "owner" nvarchar(191) not null, "expiration" int not null);

alter table "cache_locks" add constraint "cache_locks_key_primary" primary key ("key");

create index "cache_locks_expiration_index" on "cache_locks" ("expiration");

create table "jobs" ("id" bigint not null identity primary key, "queue" nvarchar(191) not null, "payload" nvarchar(max) not null, "attempts" tinyint not null, "reserved_at" int null, "available_at" int not null, "created_at" int not null);

create index "jobs_queue_index" on "jobs" ("queue");

create table "job_batches" ("id" nvarchar(191) not null, "name" nvarchar(191) not null, "total_jobs" int not null, "pending_jobs" int not null, "failed_jobs" int not null, "failed_job_ids" nvarchar(max) not null, "options" nvarchar(max) null, "cancelled_at" int null, "created_at" int not null, "finished_at" int null);

alter table "job_batches" add constraint "job_batches_id_primary" primary key ("id");

create table "failed_jobs" ("id" bigint not null identity primary key, "uuid" nvarchar(191) not null, "connection" nvarchar(max) not null, "queue" nvarchar(max) not null, "payload" nvarchar(max) not null, "exception" nvarchar(max) not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);

create unique index "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");

create table "personal_access_tokens" ("id" bigint not null identity primary key, "tokenable_type" nvarchar(191) not null, "tokenable_id" bigint not null, "name" nvarchar(max) not null, "token" nvarchar(64) not null, "abilities" nvarchar(max) null, "last_used_at" datetime null, "expires_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

create index "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens" ("tokenable_type", "tokenable_id");

create unique index "personal_access_tokens_token_unique" on "personal_access_tokens" ("token");

create index "personal_access_tokens_expires_at_index" on "personal_access_tokens" ("expires_at");

create table "permissions" ("id" bigint not null identity primary key, "name" nvarchar(191) not null, "guard_name" nvarchar(191) not null, "created_at" datetime null, "updated_at" datetime null);

create unique index "permissions_name_guard_name_unique" on "permissions" ("name", "guard_name");

create table "roles" ("id" bigint not null identity primary key, "name" nvarchar(191) not null, "guard_name" nvarchar(191) not null, "created_at" datetime null, "updated_at" datetime null);

create unique index "roles_name_guard_name_unique" on "roles" ("name", "guard_name");

create table "model_has_permissions" ("permission_id" bigint not null, "model_type" nvarchar(191) not null, "model_id" bigint not null);

create index "model_has_permissions_model_id_model_type_index" on "model_has_permissions" ("model_id", "model_type");

alter table "model_has_permissions" add constraint "model_has_permissions_permission_id_foreign" foreign key ("permission_id") references "permissions" ("id") on delete cascade;

alter table "model_has_permissions" add constraint "model_has_permissions_permission_model_type_primary" primary key ("permission_id", "model_id", "model_type");

create table "model_has_roles" ("role_id" bigint not null, "model_type" nvarchar(191) not null, "model_id" bigint not null);

create index "model_has_roles_model_id_model_type_index" on "model_has_roles" ("model_id", "model_type");

alter table "model_has_roles" add constraint "model_has_roles_role_id_foreign" foreign key ("role_id") references "roles" ("id") on delete cascade;

alter table "model_has_roles" add constraint "model_has_roles_role_model_type_primary" primary key ("role_id", "model_id", "model_type");

create table "role_has_permissions" ("permission_id" bigint not null, "role_id" bigint not null);

alter table "role_has_permissions" add constraint "role_has_permissions_permission_id_foreign" foreign key ("permission_id") references "permissions" ("id") on delete cascade;

alter table "role_has_permissions" add constraint "role_has_permissions_role_id_foreign" foreign key ("role_id") references "roles" ("id") on delete cascade;

alter table "role_has_permissions" add constraint "role_has_permissions_permission_id_role_id_primary" primary key ("permission_id", "role_id");

create table "shops" ("id" bigint not null identity primary key, "shop_code" nvarchar(50) not null, "shop_name" nvarchar(200) not null, "address" nvarchar(500) null, "city" nvarchar(100) null, "contact_person" nvarchar(150) null, "contact_number" nvarchar(30) null, "status" nvarchar(20) not null default 'active', "created_by" bigint null, "updated_by" bigint null, "created_at" datetime null, "updated_at" datetime null);

alter table "shops" add constraint "shops_created_by_foreign" foreign key ("created_by") references "users" ("id");

alter table "shops" add constraint "shops_updated_by_foreign" foreign key ("updated_by") references "users" ("id");

create index "shops_status_index" on "shops" ("status");

create index "shops_shop_name_index" on "shops" ("shop_name");

create unique index "shops_shop_code_unique" on "shops" ("shop_code");

create table "shop_user" ("id" bigint not null identity primary key, "shop_id" bigint not null, "user_id" bigint not null, "created_at" datetime null, "updated_at" datetime null);

alter table "shop_user" add constraint "shop_user_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "shop_user" add constraint "shop_user_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade;

create unique index "shop_user_unique" on "shop_user" ("shop_id", "user_id");

create table "items" ("id" bigint not null identity primary key, "product_code" nvarchar(60) not null, "barcode" nvarchar(60) null, "description" nvarchar(300) not null, "generic_name" nvarchar(200) null, "manufacturer" nvarchar(200) null, "uom" nvarchar(20) not null default 'EA', "price" decimal(18, 4) not null default '0', "status" nvarchar(20) not null default 'active', "created_by" bigint null, "updated_by" bigint null, "created_at" datetime null, "updated_at" datetime null);

alter table "items" add constraint "items_created_by_foreign" foreign key ("created_by") references "users" ("id");

alter table "items" add constraint "items_updated_by_foreign" foreign key ("updated_by") references "users" ("id");

create index "items_barcode_index" on "items" ("barcode");

create index "items_status_index" on "items" ("status");

create unique index "items_product_code_unique" on "items" ("product_code");

create table "devices" ("id" bigint not null identity primary key, "shop_id" bigint not null, "device_code" nvarchar(50) not null, "description" nvarchar(200) null, "serial_number" nvarchar(100) null, "status" nvarchar(20) not null default 'active', "last_submission_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "devices" add constraint "devices_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

create unique index "device_shop_code_unique" on "devices" ("shop_id", "device_code");

create index "devices_status_index" on "devices" ("status");

create table "stock_imports" ("id" bigint not null identity primary key, "shop_id" bigint not null, "file_name" nvarchar(255) not null, "stored_path" nvarchar(500) null, "total_records" int not null default '0', "success_records" int not null default '0', "failed_records" int not null default '0', "replaced_records" int not null default '0', "status" nvarchar(40) not null default 'pending', "failure_reason" nvarchar(500) null, "imported_by" bigint null, "imported_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "stock_imports" add constraint "stock_imports_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "stock_imports" add constraint "stock_imports_imported_by_foreign" foreign key ("imported_by") references "users" ("id");

create index "stock_imports_shop_id_index" on "stock_imports" ("shop_id");

create index "stock_imports_status_index" on "stock_imports" ("status");

create table "stock_import_errors" ("id" bigint not null identity primary key, "stock_import_id" bigint not null, "row_number" int not null, "column_name" nvarchar(100) null, "column_value" nvarchar(300) null, "error_message" nvarchar(500) not null, "created_at" datetime null, "updated_at" datetime null);

alter table "stock_import_errors" add constraint "stock_import_errors_stock_import_id_foreign" foreign key ("stock_import_id") references "stock_imports" ("id") on delete cascade;

create index "stock_import_errors_stock_import_id_index" on "stock_import_errors" ("stock_import_id");

create table "item_stocks" ("id" bigint not null identity primary key, "shop_id" bigint not null, "item_id" bigint null, "stock_import_id" bigint null, "product_code" nvarchar(60) not null, "barcode" nvarchar(60) null, "description" nvarchar(300) not null, "system_qty" decimal(18, 3) not null default '0', "uom" nvarchar(20) not null default 'EA', "price" decimal(18, 4) not null default '0', "batch" nvarchar(60) not null default '', "expiry_date" date null, "shelf_location" nvarchar(100) null, "verification_status" nvarchar(20) not null default 'not_verified', "created_at" datetime null, "updated_at" datetime null);

alter table "item_stocks" add constraint "item_stocks_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "item_stocks" add constraint "item_stocks_item_id_foreign" foreign key ("item_id") references "items" ("id");

create unique index "item_stock_identity_unique" on "item_stocks" ("shop_id", "product_code", "batch");

create index "item_stock_shop_barcode_idx" on "item_stocks" ("shop_id", "barcode");

create index "item_stocks_verification_status_index" on "item_stocks" ("verification_status");

create table "audits" ("id" bigint not null identity primary key, "shop_id" bigint not null, "device_id" bigint not null, "audit_number" int not null, "audit_date" date not null, "hht_user" nvarchar(150) null, "submitted_at" datetime null, "item_count" int not null default '0', "variance_count" int not null default '0', "status" nvarchar(25) not null default 'submitted', "verified_by" bigint null, "verified_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "audits" add constraint "audits_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "audits" add constraint "audits_device_id_foreign" foreign key ("device_id") references "devices" ("id");

alter table "audits" add constraint "audits_verified_by_foreign" foreign key ("verified_by") references "users" ("id");

create unique index "audit_identity_unique" on "audits" ("shop_id", "device_id", "audit_number");

create index "audits_status_index" on "audits" ("status");

create index "audits_audit_number_index" on "audits" ("audit_number");

create index "audits_audit_date_index" on "audits" ("audit_date");

create table "hht_submissions" ("id" bigint not null identity primary key, "submission_uid" nvarchar(80) not null, "shop_id" bigint not null, "device_id" bigint not null, "audit_number" int not null, "audit_date" date not null, "hht_user" nvarchar(150) null, "app_version" nvarchar(30) null, "item_count" int not null default '0', "payload_hash" nvarchar(64) not null, "status" nvarchar(25) not null default 'accepted', "message" nvarchar(500) null, "audit_id" bigint null, "received_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "hht_submissions" add constraint "hht_submissions_device_id_foreign" foreign key ("device_id") references "devices" ("id") on delete cascade;

create unique index "hht_submission_identity_unique" on "hht_submissions" ("shop_id", "device_id", "audit_number", "submission_uid");

create index "hht_submissions_status_index" on "hht_submissions" ("status");

create index "hht_submissions_received_at_index" on "hht_submissions" ("received_at");

create table "audit_lines" ("id" bigint not null identity primary key, "audit_id" bigint not null, "shop_id" bigint not null, "item_stock_id" bigint null, "product_code" nvarchar(60) null, "barcode" nvarchar(60) null, "description" nvarchar(300) null, "system_qty" decimal(18, 3) not null default '0', "physical_qty" decimal(18, 3) not null default '0', "variance_qty" decimal(18, 3) not null default '0', "uom" nvarchar(20) not null default 'EA', "price" decimal(18, 4) not null default '0', "batch" nvarchar(60) not null default '', "expiry_date" date null, "shelf_location" nvarchar(100) null, "is_unknown_item" bit not null default '0', "verification_status" nvarchar(20) not null default 'pending', "adjustment_status" nvarchar(20) not null default 'not_adjusted', "verified_by" bigint null, "verified_at" datetime null, "adjusted_at" datetime null, "remarks" nvarchar(500) null, "created_at" datetime null, "updated_at" datetime null);

alter table "audit_lines" add constraint "audit_lines_audit_id_foreign" foreign key ("audit_id") references "audits" ("id") on delete cascade;

alter table "audit_lines" add constraint "audit_lines_verified_by_foreign" foreign key ("verified_by") references "users" ("id");

create index "audit_lines_audit_id_index" on "audit_lines" ("audit_id");

create index "audit_line_shop_barcode_idx" on "audit_lines" ("shop_id", "barcode");

create index "audit_lines_product_code_index" on "audit_lines" ("product_code");

create index "audit_lines_verification_status_index" on "audit_lines" ("verification_status");

create index "audit_lines_adjustment_status_index" on "audit_lines" ("adjustment_status");

create table "stock_adjustments" ("id" bigint not null identity primary key, "audit_line_id" bigint null, "audit_id" bigint null, "shop_id" bigint not null, "item_stock_id" bigint null, "product_code" nvarchar(60) null, "barcode" nvarchar(60) null, "description" nvarchar(300) null, "batch" nvarchar(60) not null default '', "old_system_qty" decimal(18, 3) not null default '0', "physical_qty" decimal(18, 3) not null default '0', "variance_qty" decimal(18, 3) not null default '0', "new_system_qty" decimal(18, 3) not null default '0', "reason" nvarchar(500) null, "adjusted_by" bigint null, "adjusted_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "stock_adjustments" add constraint "stock_adjustments_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "stock_adjustments" add constraint "stock_adjustments_adjusted_by_foreign" foreign key ("adjusted_by") references "users" ("id");

create index "stock_adjustments_shop_id_index" on "stock_adjustments" ("shop_id");

create index "stock_adjustments_audit_id_index" on "stock_adjustments" ("audit_id");

create index "stock_adjustments_adjusted_at_index" on "stock_adjustments" ("adjusted_at");

create table "stock_takes" ("id" bigint not null identity primary key, "shop_id" bigint not null, "audit_id" bigint null, "audit_line_id" bigint null, "barcode" nvarchar(60) null, "product_code" nvarchar(60) null, "description" nvarchar(300) not null, "physical_qty" decimal(18, 3) not null default '0', "uom" nvarchar(20) not null default 'EA', "batch" nvarchar(60) not null default '', "expiry_date" date null, "shelf_location" nvarchar(100) null, "status" nvarchar(20) not null default 'recorded', "remarks" nvarchar(500) null, "taken_by" bigint null, "taken_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "stock_takes" add constraint "stock_takes_shop_id_foreign" foreign key ("shop_id") references "shops" ("id") on delete cascade;

alter table "stock_takes" add constraint "stock_takes_taken_by_foreign" foreign key ("taken_by") references "users" ("id");

create index "stock_takes_shop_id_index" on "stock_takes" ("shop_id");

create index "stock_takes_barcode_index" on "stock_takes" ("barcode");

create index "stock_takes_taken_at_index" on "stock_takes" ("taken_at");

create table "final_outputs" ("id" bigint not null identity primary key, "shop_id" bigint not null, "audit_id" bigint not null, "file_name" nvarchar(255) not null, "file_path" nvarchar(500) null, "record_count" int not null default '0', "verification_status" nvarchar(25) not null default 'pending', "adjustment_status" nvarchar(25) not null default 'pending', "onedrive_status" nvarchar(25) not null default 'not_uploaded', "onedrive_item_id" nvarchar(200) null, "onedrive_url" nvarchar(1000) null, "upload_attempts" int not null default '0', "last_error" nvarchar(500) null, "uploaded_at" datetime null, "generated_by" bigint null, "generated_at" datetime null, "created_at" datetime null, "updated_at" datetime null);

alter table "final_outputs" add constraint "final_outputs_audit_id_foreign" foreign key ("audit_id") references "audits" ("id") on delete cascade;

alter table "final_outputs" add constraint "final_outputs_generated_by_foreign" foreign key ("generated_by") references "users" ("id");

create index "final_outputs_audit_id_index" on "final_outputs" ("audit_id");

create index "final_outputs_onedrive_status_index" on "final_outputs" ("onedrive_status");

create table "app_settings" ("id" bigint not null identity primary key, "group_name" nvarchar(50) not null default 'general', "key_name" nvarchar(100) not null, "value" nvarchar(1000) null, "value_type" nvarchar(20) not null default 'string', "label" nvarchar(200) null, "description" nvarchar(500) null, "is_editable" bit not null default '1', "created_at" datetime null, "updated_at" datetime null);

create index "app_settings_group_name_index" on "app_settings" ("group_name");

create unique index "app_settings_key_name_unique" on "app_settings" ("key_name");
