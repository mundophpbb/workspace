<?php
/** Bounded, administrator-triggered diagnostics. No request tracking or storage. */
namespace mundophpbb\workspace\service;

class performance_probe
{
	/** Only these fixed table suffixes may be queried. */
	const TABLES = ['workspace_projects', 'workspace_files', 'workspace_activity'];

	public static function run($db, $prefix, $clock = null)
	{
		$now = $clock ?: static function () { return microtime(true); };
		$start = $now();
		$before = memory_get_usage(true);
		$peak_before = memory_get_peak_usage(true);
		$results = [];
		$queries = 0;
		foreach (self::TABLES as $suffix)
		{
			$t0 = $now();
			try
			{
				// Suffixes are constants; the prefix comes from phpBB configuration.
				$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . $prefix . $suffix);
				$row = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);
				$results[] = [
					'key' => $suffix,
					'status' => 'ok',
					'count' => max(0, (int) $row['total']),
					'ms' => round(max(0, ($now() - $t0) * 1000), 2),
				];
			}
			catch (\Throwable $e)
			{
				// No raw database error text: it may contain private configuration.
				$results[] = ['key' => $suffix, 'status' => 'unavailable', 'count' => null, 'ms' => null];
			}
			$queries++;
		}
		return [
			'rows' => $results,
			'queries' => $queries,
			'elapsed_ms' => round(max(0, ($now() - $start) * 1000), 2),
			'memory_delta_kb' => round(max(0, memory_get_usage(true) - $before) / 1024, 1),
			'peak_delta_kb' => round(max(0, memory_get_peak_usage(true) - $peak_before) / 1024, 1),
		];
	}

	/** Compare two consecutive administrator-triggered samples; no persistence. */
	public static function compare($first, $second, $thresholdMs = 100.0)
	{
		$rows = [];
		foreach (self::TABLES as $index => $key)
		{
			$a = isset($first['rows'][$index]) ? $first['rows'][$index] : [];
			$b = isset($second['rows'][$index]) ? $second['rows'][$index] : [];
			$valid = isset($a['key'], $b['key'], $a['ms'], $b['ms'])
				&& $a['key'] === $key && $b['key'] === $key
				&& $a['status'] === 'ok' && $b['status'] === 'ok'
				&& is_numeric($a['ms']) && is_numeric($b['ms']);
			$ms1 = $valid ? max(0, (float) $a['ms']) : null;
			$ms2 = $valid ? max(0, (float) $b['ms']) : null;
			// Avoid a misleading percentage when the baseline rounds to zero.
			$pct = $valid && $ms1 >= 1.0 ? round((($ms2 - $ms1) / $ms1) * 100, 1) : null;
			$rows[] = [
				'key' => $key,
				'before_ms' => $ms1,
				'after_ms' => $ms2,
				'delta_ms' => $valid ? round($ms2 - $ms1, 2) : null,
				'percent' => $pct,
				'attention' => $valid && ($ms1 >= $thresholdMs || $ms2 >= $thresholdMs),
				'available' => $valid,
			];
		}
		return [
			'rows' => $rows,
			'queries' => (int) $first['queries'] + (int) $second['queries'],
			'threshold_ms' => $thresholdMs,
		];
	}

}
