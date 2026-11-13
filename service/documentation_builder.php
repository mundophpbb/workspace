<?php
namespace mundophpbb\workspace\service;

/**
 * Workspace documentation: evidence from the saved project only.
 * Does not execute project code or infer product behavior from file names.
 */
final class documentation_builder
{
	const MAX_DRAFT_BYTES = 131072;

	public static function validLocale($locale)
	{
		return in_array((string) $locale, ['pt_br', 'en'], true);
	}

	public static function validTarget($target)
	{
		return in_array((string) $target, ['readme', 'generated'], true);
	}

	/** Bind the preview to all saved file contents, the requested output and its overwrite policy. */
	public static function token($projectId, array $snapshot, $locale, $target, $replace)
	{
		return hash('sha256', implode(':', [
			'wsp-documentation-v1', (int) $projectId, (string) ($snapshot['fingerprint'] ?? ''),
			(string) $locale, (string) $target, $replace ? '1' : '0',
		]));
	}

	private static function label($locale, $pt, $en)
	{
		return $locale === 'en' ? $en : $pt;
	}

	/** Escape extracted project metadata for Markdown, never interpolate HTML from source code. */
	private static function plain($value, $limit = 320)
	{
		if (!is_scalar($value) || is_bool($value)) return '';
		$s = trim((string) $value);
		$s = preg_replace('/[\x00-\x1f\x7f]/', ' ', $s);
		$s = preg_replace('/\s{2,}/', ' ', $s);
		if (strlen($s) > $limit) $s = substr($s, 0, $limit) . '...';
		return str_replace(['\\', '`', '*', '_', '[', ']', '<', '>', '|'],
			['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '&lt;', '&gt;', '\\|'], $s);
	}

	private static function namesInYaml(array $files, $path, $max)
	{
		$out = [];
		$text = (string) ($files[$path]['content'] ?? '');
		if ($text === '' || strlen($text) > 262144) return [];
		$pattern = $path === 'config/services.yml'
			? '/^ {4}([a-z0-9_.-]+):\s*(?:#.*)?$/mi'
			: '/^([a-z0-9_.-]+):\s*(?:#.*)?$/mi';
		if (preg_match_all($pattern, $text, $matches))
		{
			foreach ($matches[1] as $name)
			{
				if ($path === 'config/services.yml' && $name === 'services') continue;
				if ($path === 'config/routing.yml' && $name === 'imports') continue;
				$out[] = $name;
				if (count($out) >= $max) break;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param array $snapshot Canonical snapshot from release_notes_snapshot_reader.
	 * @return array{draft:string, facts:array, warnings:array}
	 */
	public function generate(array $snapshot, $locale)
	{
		if (!self::validLocale($locale)) throw new \RuntimeException('WSP_DOC_INVALID');
		$files = (array) ($snapshot['files'] ?? []);
		if (!isset($files['composer.json'])) throw new \RuntimeException('WSP_DOC_COMPOSER');
		$manifest = json_decode((string) $files['composer.json']['content'], true);
		if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'phpbb-extension' ||
			!isset($manifest['name']) || !is_string($manifest['name']) ||
			!preg_match('#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$#D', $manifest['name']))
			throw new \RuntimeException('WSP_DOC_COMPOSER');
		$parts = explode('/', $manifest['name']);
		$package = $manifest['name'];
		$title = self::plain((string) ($manifest['extra']['display-name'] ?? $package), 100);
		if ($title === '') $title = self::plain($package);
		$version = self::plain((string) ($manifest['version'] ?? ''), 80);
		$desc = self::plain((string) ($manifest['description'] ?? ''), 460);
		$license = self::plain((string) ($manifest['license'] ?? ''), 100);
		$php = self::plain((string) ($manifest['require']['php'] ?? ''), 100);
		$phpbb = self::plain((string) ($manifest['extra']['soft-require']['phpbb/phpbb'] ??
			$manifest['require']['phpbb/phpbb'] ?? ''), 100);
		$authors = [];
		foreach ((array) ($manifest['authors'] ?? []) as $author)
		{
			if (is_array($author) && !empty($author['name'])) $authors[] = self::plain($author['name'], 80);
			if (count($authors) >= 5) break;
		}
		$count = ['routes' => 0, 'services' => 0, 'migrations' => 0, 'languages' => 0,
			'templates' => 0, 'tests' => 0, 'event_files' => 0, 'workflows' => 0];
		$languageCodes = [];
		foreach (array_keys($files) as $path)
		{
			if (preg_match('#^migrations/.+\.php$#i', $path)) $count['migrations']++;
			if (preg_match('#^event/[^/]+\.php$#i', $path)) $count['event_files']++;
			if (preg_match('#^tests/.+(?:Test|_test)\.php$#i', $path)) $count['tests']++;
			if (preg_match('#^\.github/workflows/[^/]+\.ya?ml$#i', $path)) $count['workflows']++;
			if (preg_match('#^styles/[^/]+/template/.+\.(html|twig)$#i', $path)) $count['templates']++;
			if (preg_match('#^language/([a-z_]+)/[^/]+\.php$#i', $path, $m))
				$languageCodes[strtolower($m[1])] = true;
		}
		$count['languages'] = count($languageCodes);
		$routes = self::namesInYaml($files, 'config/routing.yml', 2000);
		$services = self::namesInYaml($files, 'config/services.yml', 2000);
		$count['routes'] = count($routes);
		$count['services'] = count($services);
		$yes = self::label($locale, 'Sim', 'Yes');
		$no = self::label($locale, 'Não', 'No');
		$declared = self::label($locale, 'Não declarado', 'Not declared');
		$head = [];
		$head[] = '# ' . $title;
		$head[] = '';
		$head[] = $desc !== '' ? $desc : self::label($locale,
			'Descrição não declarada em `composer.json`.', 'No description declared in `composer.json`.');
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Identificação e requisitos declarados', 'Declared identity and requirements');
		$head[] = '';
		$head[] = '- ' . self::label($locale, 'Pacote', 'Package') . ': `' . $package . '`';
		$head[] = '- ' . self::label($locale, 'Versão', 'Version') . ': ' . ($version ?: $declared);
		$head[] = '- PHP: ' . ($php ?: $declared);
		$head[] = '- phpBB: ' . ($phpbb ?: $declared);
		$head[] = '- ' . self::label($locale, 'Licença', 'License') . ': ' . ($license ?: $declared);
		if ($authors) $head[] = '- ' . self::label($locale, 'Autores', 'Authors') . ': ' . implode(', ', $authors);
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Instalação', 'Installation');
		$head[] = '';
		$head[] = '1. ' . self::label($locale,
			'Confira os requisitos acima e faça backup do fórum.', 'Check the requirements above and back up the board.');
		$head[] = '2. ' . self::label($locale, 'Coloque os arquivos da extensão na pasta',
			'Copy the extension files to') . ' `ext/' . $parts[0] . '/' . $parts[1] . '/`.';
		$head[] = '3. ' . self::label($locale,
			'No ACP, abra Personalizar → Gerenciar extensões e ative o pacote.',
			'In the ACP, open Customise → Manage extensions and enable the package.');
		$head[] = '4. ' . self::label($locale,
			'Limpe o cache do phpBB e confira a instalação em ambiente de testes.',
			'Purge the phpBB cache and verify installation in a test environment.');
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Estrutura identificada', 'Detected structure');
		$head[] = '';
		$head[] = '| ' . self::label($locale, 'Componente', 'Component') . ' | ' . self::label($locale, 'Arquivos ou registros detectados', 'Detected files or entries') . ' |';
		$head[] = '| --- | ---: |';
		foreach ([
			['Rotas (amostra YAML)', 'Routes (YAML sample)', 'routes'],
			['Serviços (amostra YAML)', 'Services (YAML sample)', 'services'],
			['Migrações PHP', 'PHP migrations', 'migrations'],
			['Listeners/eventos PHP', 'PHP event/listener files', 'event_files'],
			['Idiomas', 'Languages', 'languages'],
			['Templates HTML/Twig', 'HTML/Twig templates', 'templates'],
			['Arquivos de teste PHP', 'PHP test files', 'tests'],
			['Workflows CI', 'CI workflows', 'workflows'],
		] as $row)
			$head[] = '| ' . self::label($locale, $row[0], $row[1]) . ' | ' . (int) $count[$row[2]] . ' |';
		$head[] = '';
		$head[] = '- `config/permissions.yml`: ' . (isset($files['config/permissions.yml']) ? $yes : $no);
		$head[] = '- `CHANGELOG.md`: ' . (isset($files['CHANGELOG.md']) ? $yes : $no);
		$head[] = '- `README.md`: ' . (isset($files['README.md']) ? $yes : $no);
		if ($languageCodes)
		{
			$codes = array_keys($languageCodes); sort($codes, SORT_STRING);
			$head[] = '- ' . self::label($locale, 'Idiomas encontrados', 'Detected languages') . ': ' . implode(', ', array_map(function($x){return '`'.$x.'`';}, $codes));
		}
		if ($routes)
		{
			$head[] = '';
			$head[] = '### ' . self::label($locale, 'Rotas (identificadores encontrados)', 'Routes (detected identifiers)');
			$head[] = '';
			foreach (array_slice($routes, 0, 24) as $route) $head[] = '- `' . $route . '`';
			$head[] = '';
			$head[] = self::label($locale,
				'A lista mostra até 24 nomes; a contagem inclui todos os identificadores detectados. O YAML não comprova que as rotas funcionem.',
				'The list shows up to 24 names; the count includes all detected identifiers. YAML detection does not prove the routes work.');
		}
		if ($services)
		{
			$head[] = '';
			$head[] = '### ' . self::label($locale, 'Serviços declarados (amostra)', 'Declared services (sample)');
			$head[] = '';
			foreach (array_slice($services, 0, 24) as $service) $head[] = '- `' . $service . '`';
			$head[] = '';
			$head[] = self::label($locale,
				'A presença do serviço não garante que seu controller ou listener esteja corretamente configurado.',
				'Service declarations do not prove their controllers or listeners are correctly wired.');
		}
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Uso e configuração', 'Usage and configuration');
		$head[] = '';
		$head[] = self::label($locale,
			'_Preenchimento necessário pelo mantenedor:_ descreva os recursos, as permissões e as configurações realmente disponíveis no ACP/UCP.',
			'_Maintainer action required:_ describe the actual features, permissions and available ACP/UCP settings.');
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Atualização e recuperação', 'Upgrade and recovery');
		$head[] = '';
		$head[] = self::label($locale,
			'Faça backup dos arquivos e do banco de dados. No ACP, desative a extensão sem excluir seus dados, substitua os arquivos, reative e revise as migrações. Valide em homologação antes de atualizar produção.',
			'Back up files and the database. Disable the extension in the ACP without deleting data, replace the files, re-enable it and review migrations. Verify in staging before updating production.');
		$head[] = '';
		$head[] = '## ' . self::label($locale, 'Validação e suporte', 'Validation and support');
		$head[] = '';
		$head[] = self::label($locale,
			'A presença de testes ou workflows não indica aprovação dos testes. Execute as verificações no ambiente de desenvolvimento e revise permissões, segurança e compatibilidade antes da publicação.',
			'The presence of tests or workflows does not indicate passing tests. Run checks in development and review permissions, security and compatibility before release.');
		$head[] = '';
		$head[] = '> ' . self::label($locale,
			'Documento gerado a partir da estrutura e das declarações do código salvo. Confirme funcionalidades e instruções específicas antes de publicar.',
			'Generated from saved code structure and declarations. Confirm features and project-specific steps before publishing.');
		$head[] = '';
		$draft = implode("\n", $head);
		if (strlen($draft) > self::MAX_DRAFT_BYTES) throw new \RuntimeException('WSP_DOC_TOO_LARGE');
		return ['draft' => $draft, 'facts' => ['package' => $package, 'version' => (string) ($manifest['version'] ?? ''), 'counts' => $count]];
	}
}
