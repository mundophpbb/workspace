<?php
namespace mundophpbb\workspace\service;

/**
 * Bounded, read-only project snapshot used by the release notes generator.
 * File paths are normalized to the same extension-relative root as the
 * architecture/version comparison, so imported ZIP wrapper names do not
 * turn every file into a false add/remove on a new release.
 */
class release_notes_snapshot_reader
{
	const MAX_FILES = 1000;
	const MAX_TOTAL_BYTES = 25165824;
	const MAX_FILE_BYTES = 2097152;

	private $db;
	private $prefix;
	public function __construct(\phpbb\db\driver\driver_interface $db, $table_prefix)
	{
		$this->db=$db;
		$this->prefix=(string) $table_prefix;
	}

	public function capture($projectId)
	{
		if ($projectId <= 0) throw new \RuntimeException('WSP_RN_FAILED');
		$sql = 'SELECT file_id, file_name, file_type, file_rev, LENGTH(file_content) AS content_bytes
			FROM ' . $this->prefix . 'workspace_files WHERE project_id = ' . (int) $projectId . ' ORDER BY file_id ASC';
		$result=$this->db->sql_query_limit($sql, self::MAX_FILES+1);
		if ($result===false) throw new \RuntimeException('WSP_RN_FAILED');
		$rows=[];
		while ($row=$this->db->sql_fetchrow($result)) $rows[]=$row;
		$this->db->sql_freeresult($result);
		if (count($rows)>self::MAX_FILES) throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
		$meta=[];
		$total=0;
		foreach ($rows as $row)
		{
			$id=(int) ($row['file_id'] ?? 0);
			$path=(string) ($row['file_name'] ?? '');
			$bytes=max(0,(int) ($row['content_bytes'] ?? 0));
			if ($id<=0 || !checkpoint_manager::validPath($path) || $bytes>self::MAX_FILE_BYTES ||
				$total+$bytes>self::MAX_TOTAL_BYTES) throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
			$meta[$id]=$row;
			$total+=$bytes;
		}
		$raw=[];
		$actualTotal=0;
		// Bounded batches prevent one very large DB result set from dominating peak memory.
		foreach (array_chunk(array_keys($meta), 16) as $batch)
		{
			$sql='SELECT file_id, file_content FROM ' . $this->prefix . 'workspace_files
				WHERE project_id = ' . (int) $projectId . ' AND ' . $this->db->sql_in_set('file_id', $batch);
			$result=$this->db->sql_query($sql);
			if ($result===false) throw new \RuntimeException('WSP_RN_FAILED');
			while ($row=$this->db->sql_fetchrow($result))
			{
				$id=(int) $row['file_id'];
				if (!isset($meta[$id]) || isset($raw[$id])) throw new \RuntimeException('WSP_RN_CONFLICT');
				$content=(string) $row['file_content'];
				$actualTotal+=strlen($content);
				if (strlen($content)>self::MAX_FILE_BYTES || $actualTotal>self::MAX_TOTAL_BYTES)
					throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
				$raw[$id]=[
					'file_name'=>(string) $meta[$id]['file_name'],
					'file_type'=>(string) $meta[$id]['file_type'],
					'content'=>$content,
					'file_id'=>$id,
					'file_rev'=>(int) $meta[$id]['file_rev'],
				];
			}
			$this->db->sql_freeresult($result);
		}
		if (count($raw)!==count($meta)) throw new \RuntimeException('WSP_RN_CONFLICT');
		return $this->normalizeRows($raw);
	}

	/** Accepts checkpoint_manager::fetchCheckpoint() as well as DB rows. */
	public function normalizeCheckpoint(array $saved)
	{
		$rows=[];
		foreach ((array) ($saved['files'] ?? []) as $path=>$file)
			$rows[]=['file_name'=>(string) $path,'file_type'=>(string) $file['type'],
				'content'=>(string) $file['content'],'file_id'=>0,'file_rev'=>0];
		$normalized=$this->normalizeRows($rows);
		// Integrity fingerprint was verified by checkpoint_manager; include it in freshness token.
		$normalized['fingerprint']=hash('sha256', $normalized['fingerprint'].':'.(string) $saved['fingerprint']);
		return $normalized;
	}

	private function normalizeRows(array $rows)
	{
		$content=[];
		$recordRows=[];
		$total=0;
		foreach ($rows as $row)
		{
			$path=(string) $row['file_name'];
			if (!checkpoint_manager::validPath($path)) throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
			$body=(string) $row['content'];
			$total+=strlen($body);
			if (strlen($body)>self::MAX_FILE_BYTES || $total>self::MAX_TOTAL_BYTES)
				throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
			$normalized=release_file_map::normalizePath($path);
			if ($normalized==='') throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
			$content[$path]=$body;
			$recordRows[]=[(int) $row['file_id'],(int) $row['file_rev'], $path,
				(string) $row['file_type'],hash('sha256',$body)];
		}
		$canonical=release_file_map::canonicalize($content);
		$files=[];
		foreach ($canonical as $path=>$body)
		{
			if (!checkpoint_manager::validPath($path)) throw new \RuntimeException('WSP_RN_SIZE_LIMIT');
			$candidate=null;
			$best=-1;
			foreach ($rows as $row)
			{
				if ((string) $row['content']!==$body) continue;
				$original=release_file_map::normalizePath((string) $row['file_name']);
				if ($original!==$path && substr($original,-strlen('/'.$path))!=='/'.$path) continue;
				// Prefer an exact extension-relative row over a wrapped duplicate.
				$score=($original===$path?100000:0)-strlen($original);
				if ($candidate===null || $score>$best) { $candidate=$row; $best=$score; }
			}
			if ($candidate===null) throw new \RuntimeException('WSP_RN_CONFLICT');
			$files[$path]=[
				'content'=>(string) $body,
				'hash'=>hash('sha256',(string) $body),
				'type'=>(string) $candidate['file_type'],
				'id'=>(int) $candidate['file_id'], 'rev'=>(int) $candidate['file_rev'],
				'stored_path'=>(string) $candidate['file_name'],
			];
		}
		ksort($files,SORT_STRING);
		usort($recordRows, static function($a,$b){return strcmp(json_encode($a),json_encode($b));});
		return [
			'files'=>$files, 'count'=>count($files), 'bytes'=>$total,
			'fingerprint'=>hash('sha256',json_encode($recordRows)),
		];
	}
}
