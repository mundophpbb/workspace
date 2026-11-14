# Workspace migration inventory

**Inventory:** 23 migrations (v100–v122), 16 declared tables.

**Compatibility:** This document does NOT replace existing migrations. Installed boards rely on phpBB migration history; do not remove or rename historical classes.

**Limitation:** This inventory reflects `update_schema()` declarations. v104 includes a conditional legacy data repair, and other migrations include `update_data()` actions not executed here.

## Migration history

| Version | Declared operations | Data steps |
|---|---|---:|
| v100 | add_tables | 8 |
| v101 | — | 42 |
| v102 | — | 31 |
| v103 | — | 40 |
| v104 | — | 1 |
| v105 | add_tables | 1 |
| v106 | add_tables | 1 |
| v107 | add_tables | 1 |
| v108 | add_tables | 1 |
| v109 | add_tables | 1 |
| v110 | add_tables | 1 |
| v111 | add_tables | 1 |
| v112 | — | 1 |
| v113 | add_columns | 2 |
| v114 | drop_keys, add_unique_index | 0 |
| v115 | — | 26 |
| v116 | — | 1 |
| v117 | — | 1 |
| v118 | add_tables | 0 |
| v119 | add_tables | 1 |
| v120 | add_tables | 0 |
| v121 | — | 1 |
| v122 | — | 1 |

## Consolidated schema (reference only; not installation SQL)

### `phpbb_workspace_projects`

**Primary key:** `project_id`

| Column | Declared type |
|---|---|
| `project_id` | `UINT` |
| `project_name` | `VCHAR:255` |
| `project_desc` | `TEXT_UNI` |
| `project_time` | `TIMESTAMP` |
| `user_id` | `UINT` |
| `project_locked` | `BOOL` |
| `collaboration_mode` | `VCHAR:32` |
| `locked_by` | `UINT` |
| `locked_time` | `TIMESTAMP` |

### `phpbb_workspace_files`

**Primary key:** `file_id`

| Column | Declared type |
|---|---|
| `file_id` | `UINT` |
| `project_id` | `UINT` |
| `file_name` | `VCHAR:255` |
| `file_content` | `MTEXT_UNI` |
| `file_type` | `VCHAR:50` |
| `file_time` | `TIMESTAMP` |
| `file_rev` | `UINT` |

### `phpbb_workspace_projects_users`

**Primary key:** `id`

| Column | Declared type |
|---|---|
| `id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `role` | `VCHAR:32` |
| `added_by` | `UINT` |
| `added_time` | `TIMESTAMP` |

### `phpbb_workspace_permissions`

**Primary key:** `perm_id`

| Column | Declared type |
|---|---|
| `perm_id` | `UINT` |
| `project_id` | `UINT` |
| `entity_type` | `VCHAR:10` |
| `entity_id` | `UINT` |
| `can_view` | `BOOL` |
| `can_edit` | `BOOL` |
| `can_manage` | `BOOL` |
| `can_delete` | `BOOL` |
| `granted_by` | `UINT` |
| `granted_time` | `TIMESTAMP` |

### `phpbb_workspace_activity`

**Primary key:** `activity_id`

| Column | Declared type |
|---|---|
| `activity_id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `action` | `VCHAR:80` |
| `object_type` | `VCHAR:32` |
| `object_path` | `VCHAR:255` |
| `created_time` | `TIMESTAMP` |
| `metadata` | `TEXT_UNI` |

### `phpbb_workspace_comments`

**Primary key:** `comment_id`

| Column | Declared type |
|---|---|
| `comment_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `user_id` | `UINT` |
| `line_number` | `UINT` |
| `message` | `TEXT_UNI` |
| `resolved` | `BOOL` |
| `resolved_by` | `UINT` |
| `resolved_time` | `TIMESTAMP` |
| `created_time` | `TIMESTAMP` |
| `updated_time` | `TIMESTAMP` |

### `phpbb_workspace_file_reviews`

**Primary key:** `review_id`

| Column | Declared type |
|---|---|
| `review_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `status` | `VCHAR:32` |
| `requested_by` | `UINT` |
| `reviewed_by` | `UINT` |
| `review_note` | `TEXT_UNI` |
| `created_time` | `TIMESTAMP` |
| `updated_time` | `TIMESTAMP` |
| `reviewed_time` | `TIMESTAMP` |

### `phpbb_workspace_file_versions`

**Primary key:** `version_id`

| Column | Declared type |
|---|---|
| `version_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `user_id` | `UINT` |
| `file_name` | `VCHAR:255` |
| `file_content` | `MTEXT_UNI` |
| `content_hash` | `VCHAR:64` |
| `source` | `VCHAR:32` |
| `change_note` | `TEXT_UNI` |
| `created_time` | `TIMESTAMP` |

### `phpbb_workspace_file_locks`

**Primary key:** `lock_id`

| Column | Declared type |
|---|---|
| `lock_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `user_id` | `UINT` |
| `locked_time` | `TIMESTAMP` |
| `expires_time` | `TIMESTAMP` |
| `note` | `TEXT_UNI` |

### `phpbb_workspace_notifications`

**Primary key:** `notification_id`

| Column | Declared type |
|---|---|
| `notification_id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `actor_id` | `UINT` |
| `event` | `VCHAR:64` |
| `object_type` | `VCHAR:32` |
| `object_path` | `VCHAR:255` |
| `message_key` | `VCHAR:128` |
| `metadata` | `TEXT_UNI` |
| `is_read` | `BOOL` |
| `created_time` | `TIMESTAMP` |
| `read_time` | `TIMESTAMP` |

### `phpbb_workspace_tasks`

**Primary key:** `task_id`

| Column | Declared type |
|---|---|
| `task_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `title` | `VCHAR:180` |
| `description` | `TEXT_UNI` |
| `status` | `VCHAR:24` |
| `priority` | `VCHAR:24` |
| `assigned_to` | `UINT` |
| `created_by` | `UINT` |
| `created_time` | `TIMESTAMP` |
| `updated_by` | `UINT` |
| `updated_time` | `TIMESTAMP` |

### `phpbb_workspace_checkpoints`

**Primary key:** `checkpoint_id`

| Column | Declared type |
|---|---|
| `checkpoint_id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `label` | `VCHAR:120` |
| `kind` | `VCHAR:20` |
| `created_time` | `TIMESTAMP` |
| `file_count` | `UINT` |
| `total_bytes` | `UINT` |
| `fingerprint` | `VCHAR:64` |

### `phpbb_workspace_checkpoint_files`

**Primary key:** `checkpoint_file_id`

| Column | Declared type |
|---|---|
| `checkpoint_file_id` | `UINT` |
| `checkpoint_id` | `UINT` |
| `file_name` | `VCHAR:255` |
| `file_type` | `VCHAR:50` |
| `file_content` | `MTEXT_UNI` |
| `content_hash` | `VCHAR:64` |

### `phpbb_workspace_github_links`

**Primary key:** `project_id`

| Column | Declared type |
|---|---|
| `project_id` | `UINT` |
| `repo_owner` | `VCHAR:40` |
| `repo_name` | `VCHAR:100` |
| `repo_ref` | `VCHAR:120` |
| `head_sha` | `VCHAR:40` |
| `checked_at` | `TIMESTAMP` |
| `imported_sha` | `VCHAR:40` |
| `imported_at` | `TIMESTAMP` |
| `linked_by` | `UINT` |
| `linked_at` | `TIMESTAMP` |

### `phpbb_workspace_github_budget`

**Primary key:** `budget_id`

| Column | Declared type |
|---|---|
| `budget_id` | `UINT` |
| `window_start` | `TIMESTAMP` |
| `used_count` | `UINT` |

### `phpbb_workspace_release_records`

**Primary key:** `release_id`

| Column | Declared type |
|---|---|
| `release_id` | `UINT` |
| `project_id` | `UINT` |
| `checkpoint_id` | `UINT` |
| `version` | `VCHAR:40` |
| `package_name` | `VCHAR:180` |
| `snapshot_sha` | `VCHAR:64` |
| `notes` | `TEXT` |
| `created_by` | `UINT` |
| `created_time` | `TIMESTAMP` |

## Rules for the next migration

1. Preserve `v100`–`v122` and all migration history recorded by phpBB.
2. For a new migration, add `v123` depending on `v122`. Update the expected version range in this inventory.
3. Optimizing fresh installs would require a baseline migration and real upgrade/uninstall tests across multiple databases; this is **not** enabled in this release.
4. Run `php tools/migration_inventory.php --markdown` to recheck the chain and generate this document.
