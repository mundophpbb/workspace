<?php
/**
 * Standalone, read-only inventory of Workspace migration declarations.
 * Usage: php tools/migration_inventory.php [--markdown|--json]
 * Does not connect to a database or execute update_data() / custom actions.
 */
namespace phpbb\db\migration { class migration { public $table_prefix = 'phpbb_'; } }
namespace {
	$root = dirname(__DIR__);
	require_once $root . "/service/editor_settings.php";
	$files = glob($root . '/migrations/v*/v*.php');
	sort($files, SORT_NATURAL);
	$tables = [];
	$entries = [];
	$previous = null;
	$failures = [];
	foreach ($files as $file) {
		if (!preg_match('~/v([0-9]+)/v\1\.php$~', str_replace('\\', '/', $file), $match)) {
			$failures[] = 'Unexpected file: ' . basename($file);
			continue;
		}
		$version = 'v' . $match[1];
		require_once $file;
		$class = '\\mundophpbb\\workspace\\migrations\\' . $version . '\\' . $version;
		if (!class_exists($class)) { $failures[] = 'Missing class: ' . $version; continue; }
		$deps = method_exists($class, 'depends_on') ? $class::depends_on() : [];
		if ($previous !== null && !in_array($previous, $deps, true)) {
			$failures[] = $version . ' must depend on ' . $previous;
		}
		$instance = new $class();
		$operations = method_exists($instance, 'update_schema') ? (array) $instance->update_schema() : [];
		foreach ((array) ($operations['add_tables'] ?? []) as $name => $definition) {
			if (isset($tables[$name])) $failures[] = 'Table defined twice: ' . $name;
			$tables[$name] = $definition;
		}
		foreach ((array) ($operations['add_columns'] ?? []) as $name => $columns) {
			if (!isset($tables[$name])) { $failures[] = 'Unknown table for columns: ' . $name; continue; }
			foreach ($columns as $column => $definition) $tables[$name]['COLUMNS'][$column] = $definition;
		}
		foreach (['drop_keys', 'add_index', 'add_unique_index'] as $op) {
			foreach ((array) ($operations[$op] ?? []) as $name => $keys) {
				if (!isset($tables[$name])) { $failures[] = 'Unknown table for index: ' . $name; continue; }
				foreach ($keys as $key => $columns) {
					if ($op === 'drop_keys') unset($tables[$name]['KEYS'][$columns]);
					else $tables[$name]['KEYS'][$key] = [$op === 'add_unique_index' ? 'UNIQUE' : 'INDEX', $columns];
				}
				if ($op === 'drop_keys') {
					foreach ($keys as $keyName) unset($tables[$name]['KEYS'][$keyName]);
				}
			}
		}
		$entries[] = ['version'=>$version, 'depends_on'=>$deps, 'schema_ops'=>array_keys($operations),
			'data_steps'=>method_exists($instance, 'update_data') ? count((array) $instance->update_data()) : 0];
		$previous = $class;
	}
	$expected = range(100, 122);
	if (array_map(static function ($e) { return (int) substr($e['version'], 1); }, $entries) !== $expected) {
		$failures[] = 'Missing, reordered or unexpected historical migration versions';
	}
	if ($failures) {
		fwrite(STDERR, implode("\n", $failures) . "\n");
		exit(1);
	}
	$output = ['migration_count'=>count($entries),'table_count'=>count($tables),'migrations'=>$entries,'tables'=>$tables,
		'notice'=>'Declaration-only reference. v104 has an additional conditional legacy data repair. Does not apply migrations or replace phpBB migration history.'];
	if (in_array('--json', $argv, true)) {
		echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	} else {
		$latestVersion = count($entries) ? $entries[count($entries) - 1]['version'] : 'none';
		$nextVersion = count($entries) ? 'v' . ((int) substr($latestVersion, 1) + 1) : 'v100';
		echo "# Workspace migration inventory\n\n";
		echo "**Inventory:** " . count($entries) . " migrations (v100–" . $latestVersion . "), " . count($tables) . " declared tables.\n\n";
		echo "**Compatibility:** This document does NOT replace existing migrations. Installed boards rely on phpBB migration history; do not remove or rename historical classes.\n\n";
		echo "**Limitation:** This inventory reflects `update_schema()` declarations. v104 includes a conditional legacy data repair, and other migrations include `update_data()` actions not executed here.\n\n";
		echo "## Migration history\n\n| Version | Declared operations | Data steps |\n|---|---|---:|\n";
		foreach ($entries as $e) echo '| ' . $e['version'] . ' | ' . (implode(', ', $e['schema_ops']) ?: '—') . ' | ' . $e['data_steps'] . " |\n";
		echo "\n## Consolidated schema (reference only; not installation SQL)\n\n";
		foreach ($tables as $name => $definition) {
			echo '### `' . $name . "`\n\n";
			echo '**Primary key:** `' . ($definition['PRIMARY_KEY'] ?? 'undefined') . "`\n\n";
			echo '| Column | Declared type |' . "\n|---|---|\n";
			foreach (($definition['COLUMNS'] ?? []) as $column => $type) {
				echo '| `' . $column . '` | `' . (is_array($type) ? $type[0] : $type) . "` |\n";
			}
			echo "\n";
		}
		echo "## Rules for the next migration\n\n";
		echo "1. Preserve `v100`–`" . $latestVersion . "` and all migration history recorded by phpBB.\n";
		echo "2. For a new migration, add `" . $nextVersion . "` depending on `" . $latestVersion . "`. Update the expected version range in this inventory.\n";
		echo "3. Optimizing fresh installs would require a baseline migration and real upgrade/uninstall tests across multiple databases; this is **not** enabled in this release.\n";
		echo "4. Run `php tools/migration_inventory.php --markdown` to recheck the chain and generate this document.\n";
	}
}
