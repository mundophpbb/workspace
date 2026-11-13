<?php
namespace mundophpbb\workspace\service;

/**
 * Read-only, bounded architecture analysis of saved phpBB extension files.
 * Does not execute project source or resolve arbitrary PHP code.
 */
final class architecture_inspector
{
	const MAX_CHANGE_ROWS = 400;
	const MAX_NODES = 160;
	const MAX_EDGES = 240;

	public function compare(array $older, array $newer, array $unknownOlder = [], array $unknownNewer = [], $truncatedOlder = false, $truncatedNewer = false)
	{
		$paths = array_unique(array_merge(array_keys($older), array_keys($newer),
			array_keys($unknownOlder), array_keys($unknownNewer)));
		sort($paths, SORT_STRING);
		$summary = ['added' => 0, 'removed' => 0, 'modified' => 0, 'unchanged' => 0];
		$areas = [];
		$rows = [];
		$unverified = 0;
		foreach ($paths as $path)
		{
			$hasOld = array_key_exists($path, $older);
			$hasNew = array_key_exists($path, $newer);
			// Do not infer additions/removals when an item is skipped or outside a cap.
			if (isset($unknownOlder[$path]) || isset($unknownNewer[$path]) ||
				(!$hasOld && $truncatedOlder) || (!$hasNew && $truncatedNewer))
			{
				$unverified++;
				continue;
			}
			$status = !$hasOld ? 'added' : (!$hasNew ? 'removed' :
				(hash('sha256', (string) $older[$path]) === hash('sha256', (string) $newer[$path]) ? 'unchanged' : 'modified'));
			$summary[$status]++;
			if ($status === 'unchanged') continue;
			$area = $this->area($path);
			if (!isset($areas[$area])) $areas[$area] = 0;
			$areas[$area]++;
			if (count($rows) >= self::MAX_CHANGE_ROWS) continue;
			$rows[] = [
				'path' => (string) $path,
				'status' => $status,
				'area' => $area,
				'old_bytes' => $hasOld ? strlen((string) $older[$path]) : 0,
				'new_bytes' => $hasNew ? strlen((string) $newer[$path]) : 0,
			];
		}
		arsort($areas);
		return [
			'summary' => $summary,
			'unverified' => $unverified,
			'areas' => $areas,
			'files' => $rows,
			'listed_changes' => count($rows),
			'total_changes' => $summary['added'] + $summary['removed'] + $summary['modified'],
			'list_truncated' => $summary['added'] + $summary['removed'] + $summary['modified'] > count($rows),
		];
	}

	public function area($path)
	{
		$path = strtolower(str_replace('\\', '/', (string) $path));
		if (preg_match('#^migrations/#', $path)) return 'migrations';
		if (preg_match('#^config/permissions\.ya?ml$|^language/[^/]+/(?:acp/)?permissions\.php$#', $path)) return 'permissions';
		if (preg_match('#^config/(?:routing|routes)(?:[^/]*)\.ya?ml$#', $path)) return 'routes';
		if (preg_match('#^config/services(?:[^/]*)\.ya?ml$#', $path)) return 'services';
		if (preg_match('#^event/#', $path)) return 'events';
		if (preg_match('#^controller/#', $path)) return 'controllers';
		if (preg_match('#^language/#', $path)) return 'languages';
		if (preg_match('#^styles/#', $path)) return 'templates';
		if ($path === 'composer.json') return 'composer';
		return 'other';
	}

	private function typeOf($path)
	{
		$area = $this->area($path);
		if ($area === 'controllers') return 'controller';
		if ($area === 'services') return 'config';
		if (strpos($path, 'service/') === 0) return 'component';
		if ($area === 'events') return 'component';
		if ($area === 'migrations') return 'migration';
		if ($area === 'templates') return 'template';
		if ($area === 'languages') return 'language';
		return 'config';
	}

	/** Only first-party source files participate in the diagram. */
	private function eligibleFile($path)
	{
		if (preg_match('#(?:^|/)(?:ace|vendor|node_modules|tests|cache|images|fonts)/#i', $path)) return false;
		if (preg_match('#\.(?:min\.js|png|jpg|gif|woff2?|svg|map)$#i', $path)) return false;
		return preg_match('#^(?:controller|service|event|migrations|config|language|styles/.+/(?:template|theme))/#i', $path) ||
			$path === 'composer.json' || $path === 'ext.php';
	}

	public function graph(array $files, array $fileIds = [])
	{
		$nodes = [];
		$edges = [];
		$edgeKeys = [];
		$truncated = false;
		$addNode = function ($id, $label, $type, $path) use (&$nodes, &$truncated, $fileIds) {
			if (isset($nodes[$id])) return true;
			if (count($nodes) >= self::MAX_NODES) { $truncated = true; return false; }
			$nodes[$id] = [
				'id' => $id, 'label' => function_exists('mb_substr') ? mb_substr((string) $label, 0, 100, 'UTF-8') : substr((string) $label, 0, 100),
				'type' => $type, 'path' => (string) $path,
				'file_id' => (int) ($fileIds[$path] ?? 0),
			];
			return true;
		};
		$addEdge = function ($from, $to, $label) use (&$edges, &$edgeKeys, &$nodes, &$truncated) {
			if (!isset($nodes[$from], $nodes[$to]) || $from === $to) return;
			$key = $from . "\0" . $to . "\0" . $label;
			if (isset($edgeKeys[$key])) return;
			if (count($edges) >= self::MAX_EDGES) { $truncated = true; return; }
			$edgeKeys[$key] = true;
			$edges[] = ['from' => $from, 'to' => $to, 'kind' => $label];
		};
		foreach ($files as $path => $content)
		{
			if (!$this->eligibleFile($path)) continue;
			$addNode('f:' . $path, basename((string) $path), $this->typeOf($path), $path);
		}

		$packagePrefix = '';
		$composer = json_decode((string) ($files['composer.json'] ?? ''), true);
		if (is_array($composer) && isset($composer['name']) &&
			preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', (string) $composer['name']))
			$packagePrefix = str_replace('/', '\\', (string) $composer['name']);
		$services = $this->extractServices((string) ($files['config/services.yml'] ?? ''));
		foreach ($services as $id => $details)
		{
			if (!$addNode('s:' . $id, $id, 'service', 'config/services.yml')) continue;
			$classPath = $this->classPath($details['class'], $packagePrefix);
			if ($classPath !== '' && isset($files[$classPath]))
				$addEdge('s:' . $id, 'f:' . $classPath, 'class');
		}
		foreach ($services as $id => $details)
		{
			foreach ($details['references'] as $reference)
				if (isset($services[$reference])) $addEdge('s:' . $id, 's:' . $reference, 'inject');
		}

		$routes = $this->extractRoutes((string) ($files['config/routing.yml'] ?? ''));
		foreach ($routes as $id => $controller)
		{
			if (!$addNode('r:' . $id, $id, 'route', 'config/routing.yml')) continue;
			// Usually "service_id:method", where the method name is not a separate node.
			$target = explode(':', $controller)[0];
			if (isset($services[$target])) $addEdge('r:' . $id, 's:' . $target, 'controller');
		}
		foreach ($files as $path => $content)
		{
			if (strpos($path, 'event/') !== 0 || substr($path, -4) !== '.php') continue;
			if (!preg_match('/getSubscribedEvents\s*\(/', $content)) continue;
			if (!preg_match_all('/[\'\"](core\.[a-zA-Z0-9_.]+)[\'\"]\s*=>/', $content, $m)) continue;
			foreach (array_unique($m[1]) as $event)
			{
				if ($addNode('e:' . $event, $event, 'event', $path))
					$addEdge('e:' . $event, 'f:' . $path, 'listener');
			}
		}
		return [
			'nodes' => array_values($nodes), 'edges' => $edges,
			'summary' => [
				'nodes' => count($nodes), 'edges' => count($edges),
				'routes' => count($routes), 'services' => count($services),
				'truncated' => $truncated,
			],
			'note' => 'static',
		];
	}

	private function extractServices($content)
	{
		if (!preg_match('/^services:\s*$/m', $content, $m, PREG_OFFSET_CAPTURE)) return [];
		$content = substr($content, $m[0][1] + strlen($m[0][0]));
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*:\s*$/m', $content, $next))
		{
			$pos = strpos($content, $next[0]);
			if ($pos !== false) $content = substr($content, 0, $pos);
		}
		if (!preg_match_all('/^([ ]{2,8})([A-Za-z0-9_.-]+):\s*(?:\#[^\r\n]*)?$/m', $content, $m, PREG_OFFSET_CAPTURE)) return [];
		$min = 99;
		foreach ($m[1] as $indent) $min = min($min, strlen($indent[0]));
		$matches = [];
		foreach ($m[0] as $i => $row)
			if (strlen($m[1][$i][0]) === $min) $matches[] = ['id' => $m[2][$i][0], 'offset' => $row[1]];
		$result = [];
		foreach ($matches as $i => $row)
		{
			$block = substr($content, $row['offset'], isset($matches[$i + 1]) ?
				$matches[$i + 1]['offset'] - $row['offset'] : null);
			preg_match('/^\s+class:\s*[\'\"]?([a-zA-Z_\\\\][a-zA-Z_0-9\\\\]*)/m', $block, $class);
			preg_match_all('/[\'\"]?@([a-zA-Z_][a-zA-Z0-9_.-]*)[\'\"]?/', $block, $deps);
			$result[$row['id']] = [
				'class' => (string) ($class[1] ?? ''),
				'references' => array_values(array_unique($deps[1])),
			];
		}
		return $result;
	}

	private function classPath($class, $packagePrefix)
	{
		$class = trim((string) $class, "\\ \t\r\n\0\x0B");
		$prefix = trim((string) $packagePrefix, "\\ \t\r\n\0\x0B");
		if ($prefix === '' || stripos($class, $prefix . '\\') !== 0) return '';
		$relative = substr($class, strlen($prefix) + 1);
		if ($relative === '') return '';
		return str_replace('\\', '/', $relative) . '.php';
	}

	private function extractRoutes($content)
	{
		$result = [];
		if (!preg_match_all('/^([a-zA-Z][a-zA-Z0-9_.-]+):\s*(?:\#[^\r\n]*)?\R([\s\S]*?)(?=^[a-zA-Z][a-zA-Z0-9_.-]+:|\z)/m', $content, $routes, PREG_SET_ORDER))
			return $result;
		foreach ($routes as $entry)
		{
			if (preg_match('/_controller\s*:\s*[\'\"]?([a-zA-Z0-9_.:-]+)/', $entry[2], $controller))
				$result[$entry[1]] = $controller[1];
		}
		return $result;
	}
}
