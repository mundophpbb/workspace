# Workspace 1.57.0 — Mapa consolidado das migrations

**Inventário:** 23 migrations (v100–v122), 16 tabelas declaradas.

**Compatibilidade:** este documento NÃO substitui as migrations existentes. Instalações já atualizadas usam os registros do phpBB; não remova ou renomeie classes históricas.

**Limitação:** representa definições de `update_schema()`. A v104 possui reparação condicional de dados legados, e outras migrations têm ações `update_data()` que não são executadas por este inventário.

## Cadeia histórica

| Versão | Operações declaradas | Etapas de dados |
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

## Estrutura consolidada (referência, sem SQL de instalação)

### `phpbb_workspace_projects`

**Chave primária:** `project_id`

| Coluna | Tipo declarado |\n|---|---|\n| `project_id` | `UINT` |
| `project_name` | `VCHAR:255` |
| `project_desc` | `TEXT_UNI` |
| `project_time` | `TIMESTAMP` |
| `user_id` | `UINT` |
| `project_locked` | `BOOL` |
| `collaboration_mode` | `VCHAR:32` |
| `locked_by` | `UINT` |
| `locked_time` | `TIMESTAMP` |

### `phpbb_workspace_files`

**Chave primária:** `file_id`

| Coluna | Tipo declarado |\n|---|---|\n| `file_id` | `UINT` |
| `project_id` | `UINT` |
| `file_name` | `VCHAR:255` |
| `file_content` | `MTEXT_UNI` |
| `file_type` | `VCHAR:50` |
| `file_time` | `TIMESTAMP` |
| `file_rev` | `UINT` |

### `phpbb_workspace_projects_users`

**Chave primária:** `id`

| Coluna | Tipo declarado |\n|---|---|\n| `id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `role` | `VCHAR:32` |
| `added_by` | `UINT` |
| `added_time` | `TIMESTAMP` |

### `phpbb_workspace_permissions`

**Chave primária:** `perm_id`

| Coluna | Tipo declarado |\n|---|---|\n| `perm_id` | `UINT` |
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

**Chave primária:** `activity_id`

| Coluna | Tipo declarado |\n|---|---|\n| `activity_id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `action` | `VCHAR:80` |
| `object_type` | `VCHAR:32` |
| `object_path` | `VCHAR:255` |
| `created_time` | `TIMESTAMP` |
| `metadata` | `TEXT_UNI` |

### `phpbb_workspace_comments`

**Chave primária:** `comment_id`

| Coluna | Tipo declarado |\n|---|---|\n| `comment_id` | `UINT` |
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

**Chave primária:** `review_id`

| Coluna | Tipo declarado |\n|---|---|\n| `review_id` | `UINT` |
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

**Chave primária:** `version_id`

| Coluna | Tipo declarado |\n|---|---|\n| `version_id` | `UINT` |
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

**Chave primária:** `lock_id`

| Coluna | Tipo declarado |\n|---|---|\n| `lock_id` | `UINT` |
| `project_id` | `UINT` |
| `file_id` | `UINT` |
| `user_id` | `UINT` |
| `locked_time` | `TIMESTAMP` |
| `expires_time` | `TIMESTAMP` |
| `note` | `TEXT_UNI` |

### `phpbb_workspace_notifications`

**Chave primária:** `notification_id`

| Coluna | Tipo declarado |\n|---|---|\n| `notification_id` | `UINT` |
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

**Chave primária:** `task_id`

| Coluna | Tipo declarado |\n|---|---|\n| `task_id` | `UINT` |
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

**Chave primária:** `checkpoint_id`

| Coluna | Tipo declarado |\n|---|---|\n| `checkpoint_id` | `UINT` |
| `project_id` | `UINT` |
| `user_id` | `UINT` |
| `label` | `VCHAR:120` |
| `kind` | `VCHAR:20` |
| `created_time` | `TIMESTAMP` |
| `file_count` | `UINT` |
| `total_bytes` | `UINT` |
| `fingerprint` | `VCHAR:64` |

### `phpbb_workspace_checkpoint_files`

**Chave primária:** `checkpoint_file_id`

| Coluna | Tipo declarado |\n|---|---|\n| `checkpoint_file_id` | `UINT` |
| `checkpoint_id` | `UINT` |
| `file_name` | `VCHAR:255` |
| `file_type` | `VCHAR:50` |
| `file_content` | `MTEXT_UNI` |
| `content_hash` | `VCHAR:64` |

### `phpbb_workspace_github_links`

**Chave primária:** `project_id`

| Coluna | Tipo declarado |\n|---|---|\n| `project_id` | `UINT` |
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

**Chave primária:** `budget_id`

| Coluna | Tipo declarado |\n|---|---|\n| `budget_id` | `UINT` |
| `window_start` | `TIMESTAMP` |
| `used_count` | `UINT` |

### `phpbb_workspace_release_records`

**Chave primária:** `release_id`

| Coluna | Tipo declarado |\n|---|---|\n| `release_id` | `UINT` |
| `project_id` | `UINT` |
| `checkpoint_id` | `UINT` |
| `version` | `VCHAR:40` |
| `package_name` | `VCHAR:180` |
| `snapshot_sha` | `VCHAR:64` |
| `notes` | `TEXT` |
| `created_by` | `UINT` |
| `created_time` | `TIMESTAMP` |

## Regras para o próximo ciclo

1. Preserve `v100`–`v121` e todos os dados registrados no phpBB.
2. Para uma nova alteração, acrescente `v122` com dependência de `v121`.
3. Uma instalação limpa otimizada exigiria migração baseline e testes reais de upgrade/desinstalação em múltiplos bancos; **não** está habilitada nesta versão.
4. Rode `php tools/migration_inventory.php --markdown` para revalidar a cadeia e gerar este documento.
