<?php
/**
 * Mundo phpBB Workspace
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\workspace\service;

/**
 * Reads a ZIP archive into a list of project files.
 *
 * Pure PHP (no phpBB dependency) so it can be tested on its own. It only reads
 * the archive in memory: nothing is ever extracted to the disk, and entry names
 * are never used as file system paths.
 */
class zip_importer
{
	const MAX_ENTRIES = 5000;
	const MAX_TOTAL_BYTES = 33554432; // 32 MB of uncompressed content.

	/**
	 * @param string   $zip_path          Path of the uploaded archive.
	 * @param callable $is_allowed        function (string $path): bool, true when the extension is accepted.
	 * @param int      $max_file_bytes    Per-file size limit.
	 *
	 * @return array ['files' => [path => content], 'skipped' => [[path, reason_code]], 'root' => stripped prefix]
	 *
	 * @throws \RuntimeException with a reason code: not_zip, empty, too_many, too_big
	 */
	public function read($zip_path, $is_allowed, $max_file_bytes)
	{
		$zip = new \ZipArchive();
		if ($zip->open($zip_path) !== true)
		{
			throw new \RuntimeException('not_zip');
		}

		$count = $zip->numFiles;
		if ($count < 1)
		{
			$zip->close();
			throw new \RuntimeException('empty');
		}
		if ($count > self::MAX_ENTRIES)
		{
			$zip->close();
			throw new \RuntimeException('too_many');
		}

		$skipped = [];
		$candidates = []; // path => zip index

		for ($i = 0; $i < $count; $i++)
		{
			$name = (string) $zip->getNameIndex($i);
			$path = $this->clean_path($name);

			if ($path === null)
			{
				$skipped[] = [$name, 'unsafe'];
				continue;
			}
			if ($path === '' || substr($name, -1) === '/' || substr($name, -1) === '\\')
			{
				continue; // Directory entry.
			}
			if ($this->is_junk($path))
			{
				continue;
			}

			$candidates[$path] = $i;
		}

		$root = $this->detect_root(array_keys($candidates));
		$files = [];
		$total = 0;

		foreach ($candidates as $path => $index)
		{
			if ($root !== '')
			{
				if (strpos($path, $root) !== 0)
				{
					$skipped[] = [$path, 'outside_root'];
					continue;
				}
				$path = substr($path, strlen($root));
			}

			if (!call_user_func($is_allowed, $path))
			{
				$skipped[] = [$path, 'extension'];
				continue;
			}

			$stat = $zip->statIndex($index);
			if ($stat && (int) $stat['size'] > $max_file_bytes)
			{
				$skipped[] = [$path, 'too_large'];
				continue;
			}

			$stream = $zip->getStream($zip->getNameIndex($index));
			if (!$stream)
			{
				$skipped[] = [$path, 'unreadable'];
				continue;
			}

			// Hard cap while reading: a lying size header cannot exhaust memory.
			$content = stream_get_contents($stream, $max_file_bytes + 1);
			fclose($stream);

			if ($content === false)
			{
				$skipped[] = [$path, 'unreadable'];
				continue;
			}
			if (strlen($content) > $max_file_bytes)
			{
				$skipped[] = [$path, 'too_large'];
				continue;
			}
			if (strpos(substr($content, 0, 8192), "\0") !== false)
			{
				$skipped[] = [$path, 'binary'];
				continue;
			}

			$total += strlen($content);
			if ($total > self::MAX_TOTAL_BYTES)
			{
				$zip->close();
				throw new \RuntimeException('too_big');
			}

			$files[$path] = $content;
		}

		$zip->close();

		return ['files' => $files, 'skipped' => $skipped, 'root' => $root];
	}

	/**
	 * Normalises an archive entry name. Returns null for names that are not safe
	 * to use as a project path (absolute paths, "..", drive letters, control characters).
	 */
	protected function clean_path($name)
	{
		$name = str_replace('\\', '/', (string) $name);

		if ($name === '' || preg_match('/[\x00-\x1f\x7f<>"]/', $name))
		{
			return null;
		}
		if ($name[0] === '/' || preg_match('#^[a-zA-Z]:#', $name))
		{
			return null;
		}

		$safe = [];
		foreach (explode('/', $name) as $segment)
		{
			$segment = trim($segment);
			if ($segment === '' || $segment === '.')
			{
				continue;
			}
			if ($segment === '..')
			{
				return null;
			}
			$safe[] = $segment;
		}

		$path = implode('/', $safe);

		// Keep the trailing slash information out: callers detect directories from the original name.
		return $path;
	}

	protected function is_junk($path)
	{
		$lower = strtolower($path);

		return strpos($lower, '__macosx/') === 0
			|| strpos($lower, '/__macosx/') !== false
			|| substr($lower, -9) === '.ds_store'
			|| substr($lower, -9) === 'thumbs.db'
			|| substr($lower, -11) === 'desktop.ini';
	}

	/**
	 * Finds the prefix to strip so the project starts at the extension root:
	 * the folder holding the shallowest composer.json (outside vendor/), or the
	 * single top-level folder when every entry shares it.
	 */
	protected function detect_root(array $paths)
	{
		$best = null;
		foreach ($paths as $path)
		{
			if (strtolower(basename($path)) !== 'composer.json' || preg_match('#(^|/)vendor/#i', $path))
			{
				continue;
			}
			if ($best === null || substr_count($path, '/') < substr_count($best, '/'))
			{
				$best = $path;
			}
		}

		if ($best !== null)
		{
			$dir = dirname($best);
			return ($dir === '.' || $dir === '') ? '' : $dir . '/';
		}

		$first = null;
		foreach ($paths as $path)
		{
			$slash = strpos($path, '/');
			if ($slash === false)
			{
				return '';
			}
			$top = substr($path, 0, $slash + 1);
			if ($first === null)
			{
				$first = $top;
			}
			else if ($top !== $first)
			{
				return '';
			}
		}

		return ($first === null) ? '' : $first;
	}
}
