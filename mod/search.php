<?php
/**
 * @file mod/search.php
 */
use Friendica\App;
use Friendica\Content\Feature;
use Friendica\Content\Nav;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Database\DBM;

require_once "include/bbcode.php";
require_once 'include/security.php';
require_once 'include/conversation.php';
require_once 'mod/dirfind.php';

function search_saved_searches() {

	$o = '';

	if (! Feature::isEnabled(local_user(),'savedsearch'))
		return $o;

	$r = q("SELECT `id`,`term` FROM `search` WHERE `uid` = %d",
		intval(local_user())
	);

	if (DBM::is_result($r)) {
		$saved = [];
		foreach ($r as $rr) {
			$saved[] = [
				'id'		=> $rr['id'],
				'term'		=> $rr['term'],
				'encodedterm'	=> urlencode($rr['term']),
				'delete'	=> L10n::t('Remove term'),
				'selected'	=> ($search==$rr['term']),
			];
		}


		$tpl = get_markup_template("saved_searches_aside.tpl");

		$o .= replace_macros($tpl, [
			'$title'	=> L10n::t('Saved Searches'),
			'$add'		=> '',
			'$searchbox'	=> '',
			'$saved' 	=> $saved,
		]);
	}

	return $o;

}


function search_init(App $a) {

	$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	if (local_user()) {
		if (x($_GET,'save') && $search) {
			$r = q("SELECT * FROM `search` WHERE `uid` = %d AND `term` = '%s' LIMIT 1",
				intval(local_user()),
				dbesc($search)
			);
			if (!DBM::is_result($r)) {
				dba::insert('search', ['uid' => local_user(), 'term' => $search]);
			}
		}
		if (x($_GET,'remove') && $search) {
			dba::delete('search', ['uid' => local_user(), 'term' => $search]);
		}

		$a->page['aside'] .= search_saved_searches();

	} else {
		unset($_SESSION['theme']);
		unset($_SESSION['mobile-theme']);
	}



}



function search_post(App $a) {
	if (x($_POST,'search'))
		$a->data['search'] = $_POST['search'];
}


function search_content(App $a) {

	if (Config::get('system','block_public') && !local_user() && !remote_user()) {
		notice(L10n::t('Public access denied.') . EOL);
		return;
	}

	if (Config::get('system','local_search') && !local_user() && !remote_user()) {
		http_status_exit(403,
				["title" => L10n::t("Public access denied."),
					"description" => L10n::t("Only logged in users are permitted to perform a search.")]);
		killme();
		//notice(L10n::t('Public access denied.').EOL);
		//return;
	}

	if (Config::get('system','permit_crawling') && !local_user() && !remote_user()) {
		// Default values:
		// 10 requests are "free", after the 11th only a call per minute is allowed

		$free_crawls = intval(Config::get('system','free_crawls'));
		if ($free_crawls == 0)
			$free_crawls = 10;

		$crawl_permit_period = intval(Config::get('system','crawl_permit_period'));
		if ($crawl_permit_period == 0)
			$crawl_permit_period = 10;

		$remote = $_SERVER["REMOTE_ADDR"];
		$result = Cache::get("remote_search:".$remote);
		if (!is_null($result)) {
			$resultdata = json_decode($result);
			if (($resultdata->time > (time() - $crawl_permit_period)) && ($resultdata->accesses > $free_crawls)) {
				http_status_exit(429,
						["title" => L10n::t("Too Many Requests"),
							"description" => L10n::t("Only one search per minute is permitted for not logged in users.")]);
				killme();
			}
			Cache::set("remote_search:".$remote, json_encode(["time" => time(), "accesses" => $resultdata->accesses + 1]), CACHE_HOUR);
		} else
			Cache::set("remote_search:".$remote, json_encode(["time" => time(), "accesses" => 1]), CACHE_HOUR);
	}

	Nav::setSelected('search');

	$search = '';
	if (x($a->data,'search'))
		$search = notags(trim($a->data['search']));
	else
		$search = ((x($_GET,'search')) ? notags(trim(rawurldecode($_GET['search']))) : '');

	$tag = false;
	if (x($_GET,'tag')) {
		$tag = true;
		$search = ((x($_GET,'tag')) ? notags(trim(rawurldecode($_GET['tag']))) : '');
	}

	// contruct a wrapper for the search header
	$o .= replace_macros(get_markup_template("content_wrapper.tpl"),[
		'name' => "search-header",
		'$title' => L10n::t("Search"),
		'$title_size' => 3,
		'$content' => search($search,'search-box','search',((local_user()) ? true : false), false)
	]);

	if (strpos($search,'#') === 0) {
		$tag = true;
		$search = substr($search,1);
	}
	if (strpos($search,'@') === 0) {
		return dirfind_content($a);
	}
	if (strpos($search,'!') === 0) {
		return dirfind_content($a);
	}

	if (x($_GET,'search-option'))
		switch($_GET['search-option']) {
			case 'fulltext':
				break;
			case 'tags':
				$tag = true;
				break;
			case 'contacts':
				return dirfind_content($a, "@");
				break;
			case 'forums':
				return dirfind_content($a, "!");
				break;
		}

	if (! $search)
		return $o;

	if (Config::get('system','only_tag_search'))
		$tag = true;

	// Here is the way permissions work in the search module...
	// Only public posts can be shown
	// OR your own posts if you are a logged in member
	// No items will be shown if the member has a blocked profile wall.

	if ($tag) {
		logger("Start tag search for '".$search."'", LOGGER_DEBUG);

		$r = q("SELECT %s
			FROM `term`
				STRAIGHT_JOIN `item` ON `item`.`id`=`term`.`oid` %s
			WHERE %s AND (`term`.`uid` = 0 OR (`term`.`uid` = %d AND NOT `term`.`global`)) AND `term`.`otype` = %d AND `term`.`type` = %d AND `term`.`term` = '%s'
			ORDER BY term.created DESC LIMIT %d , %d ",
				item_fieldlists(), item_joins(), item_condition(),
				intval(local_user()),
				intval(TERM_OBJ_POST), intval(TERM_HASHTAG), dbesc(protect_sprintf($search)),
				intval($a->pager['start']), intval($a->pager['itemspage']));
	} else {
		logger("Start fulltext search for '".$search."'", LOGGER_DEBUG);

		$sql_extra = sprintf(" AND `item`.`body` REGEXP '%s' ", dbesc(protect_sprintf(preg_quote($search))));

		$r = q("SELECT %s
			FROM `item` %s
			WHERE %s AND (`item`.`uid` = 0 OR (`item`.`uid` = %s AND NOT `item`.`global`))
				$sql_extra
			GROUP BY `item`.`uri`, `item`.`id` ORDER BY `item`.`id` DESC LIMIT %d , %d",
				item_fieldlists(), item_joins(), item_condition(),
				intval(local_user()),
				intval($a->pager['start']), intval($a->pager['itemspage']));
	}

	if (! DBM::is_result($r)) {
		info(L10n::t('No results.') . EOL);
		return $o;
	}


	if ($tag) {
		$title = L10n::t('Items tagged with: %s', $search);
	} else {
		$title = L10n::t('Results for: %s', $search);
	}

	$o .= replace_macros(get_markup_template("section_title.tpl"),[
		'$title' => $title
	]);

	logger("Start Conversation for '".$search."'", LOGGER_DEBUG);
	$o .= conversation($a,$r,'search',false);

	$o .= alt_pager($a,count($r));

	logger("Done '".$search."'", LOGGER_DEBUG);

	return $o;
}

