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
		echo "# Workspace 1.57.0 — Mapa consolidado das migrations\n\n";
		echo "**Inventário:** " . count($entries) . " migrations (v100–v122), " . count($tables) . " tabelas declaradas.\n\n";
		echo "**Compatibilidade:** este documento NÃO substitui as migrations existentes. Instalações já atualizadas usam os registros do phpBB; não remova ou renomeie classes históricas.\n\n";
		echo "**Limitação:** representa definições de `update_schema()`. A v104 possui reparação condicional de dados legados, e outras migrations têm ações `update_data()` que não são executadas por este inventário.\n\n";
		echo "## Cadeia histórica\n\n| Versão | Operações declaradas | Etapas de dados |\n|---|---|---:|\n";
		foreach ($entries as $e) echo '| ' . $e['version'] . ' | ' . (implode(', ', $e['schema_ops']) ?: '—') . ' | ' . $e['data_steps'] . " |\n";
		echo "\n## Estrutura consolidada (referência, sem SQL de instalação)\n\n";
		foreach ($tables as $name => $definition) {
			echo '### `' . $name . "`\n\n";
			echo '**Chave primária:** `' . ($definition['PRIMARY_KEY'] ?? 'não definida') . "`\n\n";
			echo '| Coluna | Tipo declarado |\n|---|---|\n';
			foreach (($definition['COLUMNS'] ?? []) as $column => $type) {
				echo '| `' . $column . '` | `' . (is_array($type) ? $type[0] : $type) . "` |\n";
			}
			echo "\n";
		}
		echo "## Regras para o próximo ciclo\n\n";
		echo "1. Preserve `v100`–`v121` e todos os dados registrados no phpBB.\n";
		echo "2. Para uma nova alteração, acrescente `v122` com dependência de `v121`.\n";
		echo "3. Uma instalação limpa otimizada exigiria migração baseline e testes reais de upgrade/desinstalação em múltiplos bancos; **não** está habilitada nesta versão.\n";
		echo "4. Rode `php tools/migration_inventory.php --markdown` para revalidar a cadeia e gerar este documento.\n";
	}
}
