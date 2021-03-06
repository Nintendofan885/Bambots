<?php
/**
 Copyright 2014 Myers Enterprises II

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

namespace com_brucemyers\CategoryWatchlistBot;

use com_brucemyers\MediaWiki\MediaWiki;
use com_brucemyers\Util\FileCache;
use PDO;

class WatchResults
{
	static $reporttypes = array('B' => 'B', 'P' => '+', 'M' => '-');
	protected $dbh_tools;

	public function __construct(PDO $dbh_tools)
	{
		$this->dbh_tools = $dbh_tools;
	}

	/**
	 * Get watchlist results
	 *
	 * @param int $queryid
	 * @param array $params
	 * @param int $page -1 = atom feed
	 * @param int $max_rows
	 * @return array, pageid => ('diffdate','plusminus','category','title','ns')
	 */
	public function getResults($queryid, $params, $page, $max_rows)
	{
		$wikiname = $params['wiki'];
		$queryid = (int)$queryid;
		$origpage = $page = (int)$page;
		$page = $page - 1;
		if ($page < 0 || $page > 1000) $page = 0;
		$offset = $page * $max_rows;

		$cachesfx = $page;
		if ($origpage == -1) $cachesfx = '-1';

		$cachekey = CategoryWatchlistBot::CACHE_PREFIX_RESULT . $queryid . '_' . $cachesfx;

		// Check the cache
		$results = FileCache::getData($cachekey);
		if (! empty($results)) {
			$results = unserialize($results);
			return $results;
		}

		$subcatfound = false;
		$where = $this->buildSQLWhere($params, $subcatfound);
		if (empty($where) && ! $subcatfound) return array();

		$nosubcats = '';
		$subcats = '';

		if (! empty($where)) $nosubcats = "SELECT * FROM `{$wikiname}_diffs` WHERE $where";

		if ($subcatfound) $subcats = "SELECT diffs.* FROM `{$wikiname}_diffs` AS diffs, querycats AS qc" .
			" WHERE diffs.cat_template = 'C' AND diffs.category = qc.category AND qc.queryid = $queryid AND " .
			" (qc.plusminus = 'B' OR qc.plusminus = diffs.plusminus)";

		if (! empty($nosubcats) && ! empty($subcats)) {
			$sql = "($nosubcats) UNION ($subcats)";
		} elseif (! empty($nosubcats)) {
			$sql = $nosubcats;
		} else {
			$sql = $subcats;
		}

		$sql .= " ORDER BY id DESC " .
			" LIMIT $offset,$max_rows";

		// Get the updated pages
		$sth = $this->dbh_tools->prepare($sql);
		$sth->execute();
		$sth->setFetchMode(PDO::FETCH_ASSOC);

		$results = array();

		while ($row = $sth->fetch()) {
			$ns = MediaWiki::getNamespaceId(MediaWiki::getNamespaceName($row['pagetitle']));
			if ($ns == -1) $ns = 9999; // Hack for non english ns
			$row['ns'] = $ns;
			$row['title'] = $row['pagetitle'];
			unset($row['pagetitle']);
			$results[] = $row;
		}

		$sth->closeCursor();

		if (! count($results)) return $results;

		$serialized = serialize($results);

		FileCache::putData($cachekey, $serialized);

		return $results;
	}

	/**
	 * Build a SQL where clause with the paramaters.
	 *
	 * @param array $params Parameters
	 * @param bool $subcatfound (out) Was a subcat found?
	 * @return string Where clause or empty
	 */
	public function buildSQLWhere($params, &$subcatfound)
	{
		$where = array();
		$subcatfound = false;

		for ($x=1; $x <= 10; ++$x) {
			$catname = trim($params["cn$x"]);
			if (empty($catname)) continue;

			$reporttype = self::$reporttypes[$params["rt$x"]];
			$pagetype = $params["pt$x"];
			$matchtype = $params["mt$x"];

			if ($matchtype == 'P') {
				$catname = $this->dbh_tools->quote("%$catname%");
				$catmatch = "category LIKE $catname ";
			} elseif ($matchtype == 'E') {
				$catname = $this->dbh_tools->quote($catname);
				$catmatch = "category = $catname";
			} else {
				$subcatfound = true;
				continue;
			}

			$extra = '';
			if ($reporttype != 'B') $extra = " AND plusminus = '$reporttype'";

			$where[] = "($catmatch AND cat_template = '$pagetype'$extra)";
		}

		return implode(' OR ', $where);
	}
}