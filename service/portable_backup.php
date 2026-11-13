<?php
namespace mundophpbb\workspace\service;

/** Portable, bounded, read-only project backup format. No extraction to disk. */
class portable_backup
{
	const FORMAT = 'workspace-project-backup-v1';
	const MAX_ZIP_BYTES = 10485760;

	public static function export(array $capture, $projectId)
	{
		if (!class_exists('ZipArchive')) throw new \RuntimeException('WSP_CP_FAILED');
		$tmp = tempnam(sys_get_temp_dir(), 'wsp_bk_');
		if ($tmp === false) throw new \RuntimeException('WSP_CP_FAILED');
		$zip = new \ZipArchive();
		$opened = false;
		try {
			if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('WSP_CP_FAILED');
			$opened = true;
			$records = [];
			foreach ($capture['files'] as $path => $file) {
				if (!checkpoint_manager::validPath($path)) throw new \RuntimeException('WSP_CP_FAILED');
				$content = (string) $file['content'];
				if (!$zip->addFromString('files/' . $path, $content)) throw new \RuntimeException('WSP_CP_FAILED');
				$records[] = ['path' => $path, 'type' => (string) $file['type'],
					'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
			}
			$manifest = ['format' => self::FORMAT, 'project_id' => (int) $projectId,
				'created_utc' => gmdate('Y-m-d\\TH:i:s\\Z'), 'file_count' => count($records),
				'files' => $records, 'fingerprint' => self::recordsHash($records)];
			if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
				throw new \RuntimeException('WSP_CP_FAILED');
			if (!$zip->close()) throw new \RuntimeException('WSP_CP_FAILED');
			$opened = false;
			$data = file_get_contents($tmp);
			if ($data === false || strlen($data) > self::MAX_ZIP_BYTES) throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
			return $data;
		} finally {
			if ($opened) $zip->close();
			@unlink($tmp);
		}
	}

	public static function recordsHash(array $records)
	{
		return hash('sha256', json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** Return validated snapshot in checkpoint_manager::diff format; never extract ZIP files. */
	public static function inspect($tmp, $bytes, $projectId)
	{
		if (!class_exists('ZipArchive') || !is_file($tmp) || $bytes <= 0 || $bytes > self::MAX_ZIP_BYTES)
			throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) throw new \RuntimeException('WSP_CP_CORRUPTED');
		try {
			if ($zip->numFiles < 1 || $zip->numFiles > checkpoint_manager::MAX_FILES + 1)
				throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
			$index = $zip->locateName('manifest.json', 0);
			if ($index === false || $zip->getNameIndex($index) !== 'manifest.json')
				throw new \RuntimeException('WSP_CP_CORRUPTED');
			$meta = $zip->statIndex($index);
			if (!$meta || $meta['size'] > 262144) throw new \RuntimeException('WSP_CP_CORRUPTED');
			$manifest = json_decode($zip->getFromIndex($index), true);
			if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT ||
				($projectId !== null && (int) ($manifest['project_id'] ?? 0) !== (int) $projectId) ||
				(int) ($manifest['project_id'] ?? 0) <= 0 ||
				!isset($manifest['files']) || !is_array($manifest['files']) ||
				count($manifest['files']) !== (int) ($manifest['file_count'] ?? -1) ||
				count($manifest['files']) + 1 !== $zip->numFiles ||
				count($manifest['files']) > checkpoint_manager::MAX_FILES)
				throw new \RuntimeException('WSP_CP_CORRUPTED');
			$total = 0; $files = []; $names = []; $records = [];
			foreach ($manifest['files'] as $record) {
				if (!is_array($record) || !is_string($record['path'] ?? null) ||
					!checkpoint_manager::validPath($record['path']) || !is_string($record['type'] ?? null) ||
					strlen($record['type']) > 50 || !preg_match('/^[a-f0-9]{64}$/D', $record['sha256'] ?? '') ||
					!is_int($record['bytes'] ?? null) || $record['bytes'] < 0 ||
					$record['bytes'] > checkpoint_manager::MAX_FILE_BYTES)
					throw new \RuntimeException('WSP_CP_CORRUPTED');
				$path = $record['path']; $lower = strtolower($path);
				if (isset($names[$lower])) throw new \RuntimeException('WSP_CP_CORRUPTED');
				$names[$lower] = true;
				$total += $record['bytes'];
				if ($total > checkpoint_manager::MAX_TOTAL_BYTES) throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
				$entry = 'files/' . $path;
				$entryIndex = $zip->locateName($entry, 0);
				if ($entryIndex === false || $zip->getNameIndex($entryIndex) !== $entry)
					throw new \RuntimeException('WSP_CP_CORRUPTED');
				$stat = $zip->statIndex($entryIndex);
				if (!$stat || $stat['size'] !== $record['bytes'] || $stat['size'] > checkpoint_manager::MAX_FILE_BYTES)
					throw new \RuntimeException('WSP_CP_CORRUPTED');
				$content = $zip->getFromIndex($entryIndex);
				if (!is_string($content) || strlen($content) !== $record['bytes'] ||
					!hash_equals($record['sha256'], hash('sha256', $content)))
					throw new \RuntimeException('WSP_CP_CORRUPTED');
				$files[$path] = ['content' => $content, 'type' => $record['type'],
					'hash' => $record['sha256'], 'id' => 0, 'rev' => 0];
				$records[] = $record;
			}
			$seenEntries = [];
			foreach (range(0, $zip->numFiles - 1) as $i) {
				$name = $zip->getNameIndex($i);
				if (isset($seenEntries[$name]) ||
					($name !== 'manifest.json' &&
						(strpos($name, 'files/') !== 0 || !isset($files[substr($name, 6)]))))
					throw new \RuntimeException('WSP_CP_CORRUPTED');
				$seenEntries[$name] = true;
			}
			if (!is_string($manifest['fingerprint'] ?? null) ||
				!hash_equals(self::recordsHash($records), $manifest['fingerprint']))
				throw new \RuntimeException('WSP_CP_CORRUPTED');
			ksort($files, SORT_STRING);
			return ['files'=>$files, 'count'=>count($files), 'bytes'=>$total,
				'fingerprint'=>checkpoint_manager::fingerprint($files, false), 'manifest'=>$manifest];
		} finally { $zip->close(); }
	}
}
