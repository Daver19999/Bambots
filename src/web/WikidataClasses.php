<?php
/**
 Copyright 2016 Myers Enterprises II

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

use com_brucemyers\Util\HttpUtil;
use com_brucemyers\Util\Config;
use com_brucemyers\Util\FileCache;
use com_brucemyers\MediaWiki\WikidataItem;
use com_brucemyers\MediaWiki\WikidataWiki;
use com_brucemyers\CleanupWorklistBot\CleanupWorklistBot;

$webdir = dirname(__FILE__);
// Marker so include files can tell if they are called directly.
$GLOBALS['included'] = true;
$GLOBALS['botname'] = 'CleanupWorklistBot';
define('BOT_REGEX', '!(?:spider|bot[\s_+:,\.\;\/\\\-]|[\s_+:,\.\;\/\\\-]bot)!i');
define('CACHE_PREFIX_WDCLS', 'WDCLS:');
define('MAX_CHILD_CLASSES', 500);
define('MIN_ORPHAN_DIRECT_INST_CNT', 5);
define('PROP_INSTANCEOF', 'P31');

$instanceofIgnores = array('Q13406463');

//error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
//ini_set("display_errors", 1);

require $webdir . DIRECTORY_SEPARATOR . 'bootstrap.php';

$params = array();

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
	case 'suggest':
		$lang = isset($_REQUEST['lang']) ? $_REQUEST['lang'] : '';
		$page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
		$callback = isset($_REQUEST['callback']) ? $_REQUEST['callback'] : '';
		$userlang = isset($_REQUEST['userlang']) ? $_REQUEST['userlang'] : '';
		if ($lang && $page && $callback) perform_suggest($lang, $page, $callback, $userlang);
		exit;
}

get_params();

// Redirect to get the results so have a bookmarkable url
if (isset($_POST['id']) && isset($_SERVER['HTTP_USER_AGENT']) && ! preg_match(BOT_REGEX, $_SERVER['HTTP_USER_AGENT'])) {
	$host  = $_SERVER['HTTP_HOST'];
	$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
	$extra = 'WikidataClasses.php?id=Q' . $params['id'] . '&lang=' . urlencode($params['lang']);
	$protocol = HttpUtil::getProtocol();
	header("Location: $protocol://$host$uri/$extra", true, 302);
	exit;
}

$subclasses = get_subclasses();

display_form($subclasses);

/**
 * Display form
 *
 */
function display_form($subclasses)
{
	global $params;
	$title = '';
	if (! empty($params['id'])) {
		if (isset($subclasses['class'][0])) $title = $subclasses['class'][0];
		if ($title != "Q{$params['id']}") $title = "$title (Q{$params['id']})";
		$title = " : $title";
	} else {
		$title = ' : Widely used root classes';
	}

	$title = htmlentities($title, ENT_COMPAT, 'UTF-8');
	$rootclasslink = '&nbsp;';

	if ($params['id'] != 0) {
		$protocol = HttpUtil::getProtocol();
		$host  = $_SERVER['HTTP_HOST'];
		$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

		$extra = "WikidataClasses.php?id=Q0&amp;lang=" . urlencode($params['lang']);
		$rootclasslink = "<a href=\"$protocol://$host$uri/$extra\">view root classes</a>";
	}
    ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
	    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	    <meta name="robots" content="noindex, nofollow" />
	    <title><?php echo 'Wikidata Class Browser' . $title ?></title>
    	<link rel='stylesheet' type='text/css' href='css/catwl.css' />
    	<script type='text/javascript' src='js/jquery-2.1.1.min.js'></script>
		<script type='text/javascript' src='js/jquery.tablesorter.min.js'></script>
	</head>
	<body>
		<script type='text/javascript'>
			$(document).ready(function()
			    {
		        $('.tablesorter').tablesorter();
			    }
			);
		</script>
		<div style="display: table; margin: 0 auto;">
		<h2>Wikidata Class Browser<?php echo $title ?></h2>
        <form action="WikidataClasses.php" method="post"><table class="form">
        <tr><td><b>Class item ID</b></td><td><input id="id" name="id" type="text" size="10" value="Q<?php echo $params['id'] ?>" /></td></tr>
        <tr><td><b>Name/description<br />language code</b></td><td><input id="lang" name="lang" type="text" size="4" value="<?php echo $params['lang'] ?>" /></td></tr>
        <tr><td><input type="submit" value="Submit" /></td><td><?php echo $rootclasslink ?></td></tr>
        </table>
        </form>
        <script type="text/javascript">
            if (document.getElementById) {
                var e = document.getElementById('id');
                e.focus();
                e.select();
            }
        </script>
        <br />
<?php
	if (! empty($subclasses)) {
		if ($params['id'] != 0 && empty($subclasses['class'])) {
			echo '<h2>Class not found</h2>';

		} else {
			$protocol = HttpUtil::getProtocol();
			$host  = $_SERVER['HTTP_HOST'];
			$uri   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

			// Display class info
			if ($params['id'] == 0) {
				echo "Data as of<sup>[1]</sup>: {$subclasses['dataasof']}<br />\n";
				echo "Class count: " . intl_num_format($subclasses['classcnt']) . "<br />\n";
				echo "Root count<sup>[2]</sup>: " . intl_num_format($subclasses['rootcnt']) . "<br />\n";

			} else {
				echo "<table><tbody>\n";
				$term_text = htmlentities($subclasses['class'][0], ENT_COMPAT, 'UTF-8');
				if ($term_text != "Q{$params['id']}") $term_text = "$term_text (Q{$params['id']})";
				$url = "https://www.wikidata.org/wiki/Q" . $params['id'];
				echo "<tr><td>Name:</td><td><a class='external' href='$url' title='Wikidata link'>$term_text</a></td></tr>\n";

				if (! empty($subclasses['class'][1])) {
					$term_desc = htmlentities($subclasses['class'][1], ENT_COMPAT, 'UTF-8');
					echo "<tr><td>Description:</td><td>$term_desc</td></tr>\n";
				}

				$sparql = 'https://query.wikidata.org/#' . rawurlencode("SELECT DISTINCT ?s ?sLabel WHERE {\n" .
					"  ?s wdt:P279 wd:Q{$params['id']} .\n" .
					"  SERVICE wikibase:label { bd:serviceParam wikibase:language \"{$params['lang']}\" }\n" .
					"}\nORDER BY ?sLabel");

				$sparql = "&nbsp;&nbsp;&nbsp;(<a href='$sparql' class='external'>SPARQL query</a>)";

				echo "<tr><td>Direct subclasses:</td><td>" . intl_num_format($subclasses['class'][2]) . "$sparql</td></tr>\n";
				echo "<tr><td>Indirect subclasses:</td><td>" . intl_num_format($subclasses['class'][3]) . "</td></tr>\n";

				$sparql = 'https://query.wikidata.org/#' . rawurlencode("SELECT DISTINCT ?s ?sLabel WHERE {\n" .
					"  ?s wdt:P31 wd:Q{$params['id']} .\n" .
					"  SERVICE wikibase:label { bd:serviceParam wikibase:language \"{$params['lang']}\" }\n" .
					"}\nORDER BY ?sLabel");

				$sparql = "&nbsp;&nbsp;&nbsp;(<a href='$sparql' class='external'>SPARQL query</a>)";

				echo "<tr><td>Direct instances:</td><td>" . intl_num_format($subclasses['class'][4]) . "$sparql</td></tr>\n";
				echo "<tr><td>Indirect instances:</td><td>" . intl_num_format($subclasses['class'][5]) . "</td></tr>\n";

				// Display parents
				if (! empty($subclasses['parents'])) {
					usort($subclasses['parents'], function($a, $b) {
						return strcmp(strtolower($a[1]), strtolower($b[1]));
					});

					$parents = array();

					foreach ($subclasses['parents'] as $row) {
						$extra = "WikidataClasses.php?id=Q" . $row[0] . "&amp;lang=" . urlencode($params['lang']);
						$term_text = htmlentities($row[1], ENT_COMPAT, 'UTF-8');
						if ($term_text != "Q{$row[0]}") $term_text = "$term_text (Q{$row[0]})";
						$parents[] = "<a href=\"$protocol://$host$uri/$extra\">$term_text</a>";
					}

					$parents = implode(', ', $parents);

					$parent_label = (count($subclasses['parents']) == 1) ? 'Parent class' : 'Parent classes';

					echo "<tr><td>$parent_label:</td><td>$parents</td></tr>\n";
				} else {
					echo "<tr><td>Parent class:</td><td>root class</td></tr>\n";
				}


				echo "<tr><td>Data as of<sup>[1]</sup>:</td><td>{$subclasses['dataasof']}</td></tr>\n";
				echo "</tbody></table>\n";
			}

			// Display children
			if (! empty($subclasses['children'])) {
				if ($params['id'] == 0) {
					echo "<h2>Widely used root classes</h2>\n";
				} else {
					$child_label = (count($subclasses['children']) == 1) ? 'Direct subclass' : 'Direct subclasses';
					echo "<h2>$child_label</h2>\n";
					usort($subclasses['children'], function($a, $b) {
						return strcmp(strtolower($a[1]), strtolower($b[1]));
					});
				}

				echo "<table class='wikitable tablesorter'><thead><tr><th>Name</th><th>Wikidata link</th><th>Direct subclasses</th>" .
					"<th>Indirect subclasses</th><th>Direct instances</th><th>Indirect instances</th></tr></thead><tbody>\n";

				foreach ($subclasses['children'] as $row) {
					$extra = "WikidataClasses.php?id=Q" . $row[0] . "&amp;lang=" . urlencode($params['lang']);
					$classurl = "$protocol://$host$uri/$extra";
					$wdurl = "https://www.wikidata.org/wiki/Q" . $row[0];
					$term_text = htmlentities($row[1], ENT_COMPAT, 'UTF-8');
					echo "<tr><td><a href='$classurl'>$term_text</a></td><td data-sort-value='$row[0]'><a class='external' href='$wdurl'>Q{$row[0]}</a></td>" .
						"<td style='text-align:right' data-sort-value='$row[2]'>" . intl_num_format($row[2]) .
						"</td><td style='text-align:right' data-sort-value='$row[3]'>" . intl_num_format($row[3]) .
						"</td><td style='text-align:right' data-sort-value='$row[4]'>" . intl_num_format($row[4]) .
						"</td><td style='text-align:right' data-sort-value='$row[5]'>" . intl_num_format($row[5]) . "</td></tr>\n";
				}

				echo "</tbody></table>\n";

			} elseif ($subclasses['class'][2] > MAX_CHILD_CLASSES) {
				echo "<h2>&gt; " . MAX_CHILD_CLASSES . " subclasses</h2>\n";
			}
		}
	}
?>
       <br /><div><sup>1</sup>Data derived from database dump wikidatawiki-pages-articles.xml</div>
       <?php if ($params['id'] == 0) {?><div><sup>2</sup>Root classes with no child classes and less than <?php echo MIN_ORPHAN_DIRECT_INST_CNT; ?> instances are excluded.</div><?php } ?>
       <div>Note: Names/descriptions are cached, so changes may not be seen until the next data load.</div>
       <div>Note: Numbers are formatted with the ISO recommended international thousands separator 'thin space'.</div>
       <div>Note: Some totals may not balance due to a class having the same super-parent class multiple times.</div>
       <div>Author: <a href="https://www.wikidata.org/wiki/User:Bamyers99">Bamyers99</a></div></div></body></html><?php
}

/**
 * Get subclasses
 */
function get_subclasses()
{
	global $params;

	$cachekey = CACHE_PREFIX_WDCLS . $params['id'] . '_' . $params['lang'];

	// Check the cache
	$results = FileCache::getData($cachekey);
	if (! empty($results)) {
		$results = unserialize($results);
		return $results;
	}

	$return = array();
	$parents = array();
	$children = array();
	$class = array();

	$wikiname = 'enwiki';
	$user = Config::get(CleanupWorklistBot::LABSDB_USERNAME);
	$pass = Config::get(CleanupWorklistBot::LABSDB_PASSWORD);
	$wiki_host = Config::get('CleanupWorklistBot.wiki_host'); // Used for testing
	if (empty($wiki_host)) $wiki_host = "$wikiname.labsdb";

	$dbh_wiki = new PDO("mysql:host=$wiki_host;dbname={$wikiname}_p;charset=utf8", $user, $pass);
	$dbh_wiki->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$sth = $dbh_wiki->query("SELECT * FROM s51454__wikidata.subclasstotals WHERE qid = 0");

	$row = $sth->fetch(PDO::FETCH_NUM);
	$classcnt = $row[2];
	$rootcnt = $row[3];
	$dataasof = $row[6];

	// Retrieve the class
	if ($params['id'] != 0) {
		$sql = "SELECT wbt.term_text AS lang_text, wbten.term_text AS en_text, wbd.term_text AS lang_desc, wbden.term_text AS en_desc, " .
				" sct.directchildcnt, sct.indirectchildcnt, sct.directinstcnt, sct.indirectinstcnt " .
				" FROM s51454__wikidata.subclasstotals sct " .
				" LEFT JOIN wikidatawiki_p.wb_terms wbt ON sct.qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' " .
				" AND wbt.term_type = 'label' AND wbt.term_language = ? " .
				" LEFT JOIN wikidatawiki_p.wb_terms wbten ON sct.qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' " .
				" AND wbten.term_type = 'label' AND wbten.term_language = 'en' " .
				" LEFT JOIN wikidatawiki_p.wb_terms wbd ON sct.qid = wbd.term_entity_id AND wbd.term_entity_type = 'item' " .
				" AND wbd.term_type = 'description' AND wbd.term_language = ? " .
				" LEFT JOIN wikidatawiki_p.wb_terms wbden ON sct.qid = wbden.term_entity_id AND wbden.term_entity_type = 'item' " .
				" AND wbden.term_type = 'description' AND wbden.term_language = 'en' " .
				" WHERE sct.qid = ? LIMIT 1";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, $params['lang']);
		$sth->bindValue(2, $params['lang']);
		$sth->bindValue(3, $params['id']);

		$sth->execute();

		if ($row = $sth->fetch(PDO::FETCH_NAMED)) {
			$term_text = $row['lang_text'];
			if (is_null($term_text)) $term_text = $row['en_text'];
			if (is_null($term_text)) $term_text = 'Q' . $params['id'];

			$term_desc = $row['lang_desc'];
			if (is_null($term_desc)) $term_desc = $row['en_desc'];
			if (is_null($term_desc)) $term_desc = '';

			$class = array($term_text, $term_desc, $row['directchildcnt'], $row['indirectchildcnt'],
					$row['directinstcnt'], $row['indirectinstcnt']);
		}
	}

	// Retrieve the parent classes
	if ($params['id'] != 0) {
		$en_text = '';
		if ($params['lang'] != 'en') $en_text = ', wbten.term_text AS en_text';

		$sql = "SELECT scc.parent_qid, wbt.term_text AS lang_text $en_text ";
		$sql .= " FROM s51454__wikidata.subclassclasses scc ";
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbt ON scc.parent_qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' ";
		$sql .= " AND wbt.term_type = 'label' AND wbt.term_language = ? ";
		if ($params['lang'] != 'en') {
			$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbten ON scc.parent_qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' ";
			$sql .= " AND wbten.term_type = 'label' AND wbten.term_language = 'en' ";
		}
		$sql .= " WHERE scc.child_qid = ? ";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, $params['lang']);
		$sth->bindValue(2, $params['id']);

		$sth->execute();

		while ($row = $sth->fetch(PDO::FETCH_NAMED)) {
			$term_text = $row['lang_text'];
			if (is_null($term_text) && $params['lang'] != 'en') $term_text = $row['en_text'];
			if (is_null($term_text)) $term_text = 'Q' . $row['parent_qid'];

			$parents[$row['parent_qid']] = array($row['parent_qid'], $term_text); // removes dup terms
		}
	}

	// Retrieve the child classes
	if ($params['id'] == 0) {
		$en_text = '';
		if ($params['lang'] != 'en') $en_text = 'wbten.term_text AS en_text,';

		$sql = "SELECT sct.qid, wbt.term_text AS lang_text, $en_text ";
		$sql .= " sct.directchildcnt, sct.indirectchildcnt, sct.directinstcnt, sct.indirectinstcnt ";
		$sql .= " FROM s51454__wikidata.subclasstotals sct ";
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbt ON sct.qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' ";
		$sql .= " AND wbt.term_type = 'label' AND wbt.term_language = ? ";
		if ($params['lang'] != 'en') {
			$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbten ON sct.qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' ";
			$sql .= " AND wbten.term_type = 'label' AND wbten.term_language = 'en' ";
		}
		$sql .= " WHERE sct.root = 'Y' ";
		$sql .= " ORDER BY sct.directchildcnt + sct.indirectchildcnt + sct.directinstcnt + sct.indirectinstcnt DESC LIMIT 200";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, $params['lang']);

	} elseif (! empty($class) && $class[2] <= MAX_CHILD_CLASSES) { // directchildcnt
		$en_text = '';
		if ($params['lang'] != 'en') $en_text = 'wbten.term_text AS en_text,';

		$sql = "SELECT scc.child_qid AS qid, wbt.term_text AS lang_text, $en_text ";
		$sql .= " sct.directchildcnt, sct.indirectchildcnt, sct.directinstcnt, sct.indirectinstcnt ";
		$sql .= " FROM s51454__wikidata.subclassclasses scc ";
		$sql .= " JOIN s51454__wikidata.subclasstotals sct ON sct.qid = scc.child_qid ";
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbt ON scc.child_qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' ";
		$sql .= " AND wbt.term_type = 'label' AND wbt.term_language = ? ";
		if ($params['lang'] != 'en') {
			$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbten ON scc.child_qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' ";
			$sql .= " AND wbten.term_type = 'label' AND wbten.term_language = 'en' ";
		}
		$sql .= " WHERE scc.parent_qid = ?";

		$sth = $dbh_wiki->prepare($sql);
		$sth->bindValue(1, $params['lang']);
		$sth->bindValue(2, $params['id']);
	}

	$sth->execute();

	while ($row = $sth->fetch(PDO::FETCH_NAMED)) {
		$term_text = $row['lang_text'];
		if (is_null($term_text) && $params['lang'] != 'en') $term_text = $row['en_text'];
		if (is_null($term_text)) $term_text = 'Q' . $row['qid'];

		$children[$row['qid']] = array($row['qid'], $term_text, $row['directchildcnt'], $row['indirectchildcnt'],
			$row['directinstcnt'], $row['indirectinstcnt']); // removes dup terms
	}

	$return = array('class' => $class, 'parents' => $parents, 'children' => $children, 'dataasof' => $dataasof,
		'classcnt' => $classcnt, 'rootcnt' => $rootcnt);

	$serialized = serialize($return);

	FileCache::putData($cachekey, $serialized);

	return $return;
}

/**
 * Get the input parameters
 */
function get_params()
{
	global $params;

	$params = array();

	$params['id'] = '0';
	if (isset($_REQUEST['id'])) $params['id'] = trim($_REQUEST['id']);
	if (empty($params['id'])) $params['id'] = '0';
	if (! is_numeric($params['id'][0])) $params['id'] = substr($params['id'], 1);
	if (empty($params['id'])) $params['id'] = '0';
	$params['id'] = intval($params['id']);
	$params['lang'] = isset($_REQUEST['lang']) ? $_REQUEST['lang'] : '';

	if (! empty($params['lang']) && preg_match('!([a-zA-Z]+)!', $params['lang'], $matches)) {
		$params['lang'] = strtolower($matches[1]);
	}

	if (empty($params['lang']) && isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('!([a-zA-Z]+)!', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches)) {
		$params['lang'] = strtolower($matches[1]);
	}
	if (empty($params['lang'])) $params['lang'] = 'en';
}

/**
 * Format an integer
 *
 * @param int $number
 */
function intl_num_format($number)
{
	return number_format($number, 0, '', '&thinsp;');
}

/**
 * Suggest a class for an items instanceOf property
 *
 * @param string $lang
 * @param string $page
 * @param string $callback
 * @param string $userlang
 * @return JSONP
 */
function perform_suggest($lang, $page, $callback, $userlang)
{
	global $instanceofIgnores;
	header('content-type: application/json; charset=utf-8');
	header('access-control-allow-origin: *');

	$lang = preg_replace('!\W!', '', $lang);

	$wikiname = "{$lang}wiki";
	$user = Config::get(CleanupWorklistBot::LABSDB_USERNAME);
	$pass = Config::get(CleanupWorklistBot::LABSDB_PASSWORD);
	$wiki_host = Config::get('CleanupWorklistBot.wiki_host'); // Used for testing
	if (empty($wiki_host)) $wiki_host = "$wikiname.labsdb";

	$dbh_wiki = new PDO("mysql:host=$wiki_host;dbname={$wikiname}_p;charset=utf8", $user, $pass);
	$dbh_wiki->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$page = str_replace(' ', '_', $page);

	// Retrieve the pages categories
	$sql = 'SELECT cat_title, cat_pages - (cat_subcats + cat_files) AS pagecnt ' .
		' FROM page ' .
		' JOIN categorylinks cl ON page.page_id = cl_from ' .
		' JOIN category cat ON cl_to = cat_title ' .
		' LEFT JOIN page catpage ON cat_title = catpage.page_title ' .
		" LEFT JOIN page_props ON pp_page = catpage.page_id AND pp_propname = 'hiddencat' " .
		' WHERE page.page_namespace = 0 AND page.page_title = ? ' .
		' AND catpage.page_namespace = 14 AND pp_value IS NULL ' .
		' LIMIT 10 ';

	$sth = $dbh_wiki->prepare($sql);
	$sth->bindValue(1, $page);

	$sth->execute();
	$sth->setFetchMode(PDO::FETCH_NAMED);
	$cat = '';
	$minpages = PHP_INT_MAX;

	// Choose the smallest category with at least 10 pages and no year in the title
	while ($row = $sth->fetch()) {
		$cattitle = $row['cat_title'];
		$pagecnt = $row['pagecnt'];

		if (preg_match('!\d{4}!', $cattitle)) continue;
		if ($pagecnt < 10) continue;
		if ($pagecnt < $minpages) {
			$cat = $cattitle;
			$minpages = $pagecnt;
		}
	}

	$sth->closeCursor();

	if (! $cat) {
		echo "/**/$callback({});";
		return;
	}

	// Retrieve the category member qids
	$sql = 'SELECT pp_value ' .
		' FROM categorylinks ' .
		' JOIN page_props ON cl_from = pp_page ' .
       	" WHERE cl_to = ? AND pp_propname = 'wikibase_item' AND cl_type = 'page' " .
       	' ORDER BY cl_sortkey_prefix ' . // weed out * sort key etc
		' LIMIT 10 ';

	$sth = $dbh_wiki->prepare($sql);
	$sth->bindValue(1, $cat);

	$sth->execute();
	$sth->setFetchMode(PDO::FETCH_NUM);
	$qids = array();

	while ($row = $sth->fetch()) {
		$qids[] = $row[0];
	}

	$sth->closeCursor();

	if (! $qids) {
		echo "/**/$callback({});";
		return;
	}

	// Retrieve the item claims and look for instance of
	$wdwiki = new WikidataWiki();

	$items = $wdwiki->getItemsNoCache($qids);

	$instanceofs = array();

	foreach ($items as $item) {
		$propvalues = $item->getStatementsOfType(WikidataItem::TYPE_INSTANCE_OF);

		foreach ($propvalues as $qid) {
			if (in_array($qid, $instanceofIgnores)) continue;
			$qid = substr($qid, 1);

			if (! isset($instanceofs[$qid])) $instanceofs[$qid] = array('catcnt' => 0);
			++$instanceofs[$qid]['catcnt'];
		}
	}

	foreach ($instanceofs as $qid => $info) {
		if ($info['catcnt'] == 1) unset($instanceofs[$qid]);
	}

	if (! $instanceofs) {
		echo "/**/$callback({});";
		return;
	}

	// Retrieve the name and description
	$qids = array_keys($instanceofs);

	$en_text = '';
	$en_desc = '';
	if ($userlang != 'en') {
		$en_text = 'wbten.term_text AS en_text,';
		$en_desc = 'wbden.term_text AS en_desc,';
	}

	$sql = "SELECT DISTINCT sct.qid, $en_text $en_desc wbt.term_text AS lang_text, wbd.term_text AS lang_desc, sct.allparents ";
	$sql .= " FROM s51454__wikidata.subclasstotals sct ";
	$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbt ON sct.qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' ";
	$sql .= " AND wbt.term_type = 'label' AND wbt.term_language = ? ";
	if ($userlang != 'en') {
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbten ON sct.qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' ";
		$sql .= " AND wbten.term_type = 'label' AND wbten.term_language = 'en' ";
	}
	$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbd ON sct.qid = wbd.term_entity_id AND wbd.term_entity_type = 'item' ";
	$sql .= " AND wbd.term_type = 'description' AND wbd.term_language = ? ";
	if ($userlang != 'en') {
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbden ON sct.qid = wbden.term_entity_id AND wbden.term_entity_type = 'item' ";
		$sql .= " AND wbden.term_type = 'description' AND wbden.term_language = 'en' ";
	}
	$sql .= " WHERE sct.qid IN (" . implode(',', $qids) . ") ";
	$sql .= " LIMIT 10 ";

	$sth = $dbh_wiki->prepare($sql);
	$sth->bindValue(1, $userlang);
	$sth->bindValue(2, $userlang);
	$sth->execute();
	$sth->setFetchMode(PDO::FETCH_NAMED);

	while ($row = $sth->fetch()) {
		$term_text = $row['lang_text'];
		if (is_null($term_text) && $userlang != 'en') $term_text = $row['en_text'];
		if (is_null($term_text)) $term_text = 'Q' . $row['qid'];

		$term_desc = $row['lang_desc'];
		if (is_null($term_desc) && $userlang != 'en') $term_desc = $row['en_desc'];
		if (is_null($term_desc)) $term_desc = '';

		$qid = $row['qid'];
		$instanceofs[$qid]['label'] = $term_text;
		$instanceofs[$qid]['desc'] = $term_desc;
		$instanceofs[$qid]['allparents'] = explode('|', $row['allparents']);
	}

	$sth->closeCursor();

	// Retrieve the child classes

	$sql = "SELECT DISTINCT scc.child_qid AS qid, wbt.term_text AS lang_text, wbd.term_text AS lang_desc, $en_text $en_desc ";
	$sql .= " sct.allparents ";
	$sql .= " FROM s51454__wikidata.subclassclasses scc ";
	$sql .= " JOIN s51454__wikidata.subclasstotals sct ON sct.qid = scc.child_qid ";
	$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbt ON scc.child_qid = wbt.term_entity_id AND wbt.term_entity_type = 'item' ";
	$sql .= " AND wbt.term_type = 'label' AND wbt.term_language = ? ";
	if ($userlang != 'en') {
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbten ON scc.child_qid = wbten.term_entity_id AND wbten.term_entity_type = 'item' ";
		$sql .= " AND wbten.term_type = 'label' AND wbten.term_language = 'en' ";
	}
	$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbd ON sct.qid = wbd.term_entity_id AND wbd.term_entity_type = 'item' ";
	$sql .= " AND wbd.term_type = 'description' AND wbd.term_language = ? ";
	if ($userlang != 'en') {
		$sql .= " LEFT JOIN wikidatawiki_p.wb_terms wbden ON sct.qid = wbden.term_entity_id AND wbden.term_entity_type = 'item' ";
		$sql .= " AND wbden.term_type = 'description' AND wbden.term_language = 'en' ";
	}
	$sql .= " WHERE scc.parent_qid IN (" . implode(',', $qids) . ") AND sct.directinstcnt + sct.indirectinstcnt > 0 ";
	$sql .= " ORDER BY sct.directinstcnt + sct.indirectinstcnt DESC ";
	$sql .= " LIMIT 10 ";

	$sth = $dbh_wiki->prepare($sql);
	$sth->bindValue(1, $userlang);
	$sth->bindValue(2, $userlang);
	$sth->execute();
	$sth->setFetchMode(PDO::FETCH_NAMED);

	while ($row = $sth->fetch()) {
		$qid = $row['qid'];
		if (isset($instanceofs[$qid])) continue;

		$term_text = $row['lang_text'];
		if (is_null($term_text) && $userlang != 'en') $term_text = $row['en_text'];
		if (is_null($term_text)) $term_text = 'Q' . $row['qid'];

		$term_desc = $row['lang_desc'];
		if (is_null($term_desc) && $userlang != 'en') $term_desc = $row['en_desc'];
		if (is_null($term_desc)) $term_desc = '';

		$instanceofs[$qid] = array('label' => $term_text, 'desc' => $term_desc, 'allparents' => explode('|', $row['allparents']));
	}

	$sth->closeCursor();

	// Calc the class hierarchy

	$parentchilds = array();

	foreach ($instanceofs as $childqid => $info) {
		foreach ($info['allparents'] as $parentqid) {
			if (isset($instanceofs[$parentqid])) {
				if (! isset($parentchilds[$parentqid])) $parentchilds[$parentqid] = array();
				$parentchilds[$parentqid][] = $childqid;
			}
		}

		unset($instanceofs[$childqid]['allparents']);
	}

	$sugs = array();

	// Add each child to its parent
	foreach ($parentchilds as $parentqid => $childqids) {
		if (! isset($instanceofs[$parentqid])) continue;
		$childs = array();

		foreach ($childqids as $childqid) {
			if (isset($instanceofs[$childqid])) {
				$childs['Q' . $childqid] = $instanceofs[$childqid];
				unset($instanceofs[$childqid]);
			}
		}

		$sugs['Q' . $parentqid] = $instanceofs[$parentqid];
		unset($instanceofs[$parentqid]);

		if (! empty($childs)) $sugs['Q' . $parentqid]['childs'] = $childs;
	}

	// Add the parentless ones
	foreach ($instanceofs as $qid => $info) {
		$sugs['Q' . $qid] = $info;
	}

	echo "/**/$callback(" . json_encode($sugs) . ");";
}
?>