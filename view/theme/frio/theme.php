<?php
/*
 * Name: frio
 * Description: Bootstrap V3 theme. The theme is currently under construction, so it is far from finished. For further information have a look at the <a href="https://github.com/friendica/friendica/tree/develop/view/theme/frio/README.md">ReadMe</a>.
 * Version: V.0.8
 * Author: Rabuzarus <https://friendica.kommune4.de/profile/rabuzarus>
 *
 */
use Friendica\App;
use Friendica\Content\Widget;
use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBM;
use Friendica\Model\Profile;
use Friendica\Object\Image;

$frio = "view/theme/frio";

global $frio;

function frio_init(App $a)
{
	// disable the events module link in the profile tab
	$a->theme_events_in_profile = false;
	$a->videowidth = 622;

	$a->set_template_engine('smarty3');

	$baseurl = System::baseUrl();

	$style = PConfig::get(local_user(), 'frio', 'style');

	$frio = "view/theme/frio";

	global $frio;

	// if the device is a mobile device set js is_mobile
	// variable so the js scripts can use this information
	if ($a->is_mobile || $a->is_tablet) {
		$a->page["htmlhead"] .= <<< EOT
			<script type="text/javascript">
				var is_mobile = 1;
			</script>
EOT;
	}

	if ($style == "") {
		$style = Config::get('frio', 'style');
	}
}

function frio_install()
{
	Addon::registerHook('prepare_body_final', 'view/theme/frio/theme.php', 'frio_item_photo_links');
	Addon::registerHook('item_photo_menu', 'view/theme/frio/theme.php', 'frio_item_photo_menu');
	Addon::registerHook('contact_photo_menu', 'view/theme/frio/theme.php', 'frio_contact_photo_menu');
	Addon::registerHook('nav_info', 'view/theme/frio/theme.php', 'frio_remote_nav');
	Addon::registerHook('acl_lookup_end', 'view/theme/frio/theme.php', 'frio_acl_lookup');
	Addon::registerHook('display_item', 'view/theme/frio/theme.php', 'frio_display_item');

	logger("installed theme frio");
}

function frio_uninstall()
{
	Addon::unregisterHook('prepare_body_final', 'view/theme/frio/theme.php', 'frio_item_photo_links');
	Addon::unregisterHook('item_photo_menu', 'view/theme/frio/theme.php', 'frio_item_photo_menu');
	Addon::unregisterHook('contact_photo_menu', 'view/theme/frio/theme.php', 'frio_contact_photo_menu');
	Addon::unregisterHook('nav_info', 'view/theme/frio/theme.php', 'frio_remote_nav');
	Addon::unregisterHook('acl_lookup_end', 'view/theme/frio/theme.php', 'frio_acl_lookup');
	Addon::unregisterHook('display_item', 'view/theme/frio/theme.php', 'frio_display_item');

	logger("uninstalled theme frio");
}

/**
 * @brief Replace friendica photo links hook
 *
 *  This function does replace the links to photos
 *  of other friendica users. Original the photos are
 *  linked to the photo page. Now they will linked directly
 *  to the photo file. This function is nessesary to use colorbox
 *  in the network stream
 *
 * @param App $a Unused but required by hook definition
 * @param array $body_info The item and its html output
 */
function frio_item_photo_links(App $a, &$body_info)
{
	$phototypes = Image::supportedTypes();
	$occurence = 1;
	$p = bb_find_open_close($body_info['html'], "<a", ">");

	while ($p !== false && ($occurence++ < 500)) {
		$link = substr($body_info['html'], $p['start'], $p['end'] - $p['start']);
		$matches = [];

		preg_match("/\/photos\/[\w]+\/image\/([\w]+)/", $link, $matches);
		if ($matches) {
			// Replace the link for the photo's page with a direct link to the photo itself
			$newlink = str_replace($matches[0], "/photo/{$matches[1]}", $link);

			// Add a "quiet" parameter to any redir links to prevent the "XX welcomes YY" info boxes
			$newlink = preg_replace("/href=\"([^\"]+)\/redir\/([^\"]+)&url=([^\"]+)\"/", 'href="$1/redir/$2&quiet=1&url=$3"', $newlink);

			// Having any arguments to the link for Colorbox causes it to fetch base64 code instead of the image
			$newlink = preg_replace("/\/[?&]zrl=([^&\"]+)/", '', $newlink);

			$body_info['html'] = str_replace($link, $newlink, $body_info['html']);
		}

		$p = bb_find_open_close($body_info['html'], "<a", ">", $occurence);
	}
}

/**
 * @brief Replace links of the item_photo_menu hook
 *
 *  This function replaces the original poke and the message links
 *  to call the addToModal javascript function so this pages can
 *  be loaded in a bootstrap modal
 *
 * @param App $a Unused but required by the hook definition
 * @param array $arr Contains item data and the original photo_menu
 */
function frio_item_photo_menu(App $a, &$arr)
{
	foreach ($arr["menu"] as $k => $v) {
		if (strpos($v, 'poke/?f=&c=') === 0 || strpos($v, 'message/new/') === 0) {
			$v = "javascript:addToModal('" . $v . "'); return false;";
			$arr["menu"][$k] = $v;
		}
	}
}

/**
 * @brief Replace links of the contact_photo_menu
 *
 *  This function replaces the original poke and the message links
 *  to call the addToModal javascript function so this pages can
 *  be loaded in a bootstrap modal
 *  Additionally the profile, status and photo page links  will be changed
 *  to don't open in a new tab if the contact is a friendica contact.
 *
 * @param App $a The app data
 * @param array $args Contains contact data and the original photo_menu
 */
function frio_contact_photo_menu(App $a, &$args)
{
	$cid = $args["contact"]["id"];
	$pokelink = $args["menu"]["poke"][1];
	$pmlink = $args["menu"]["pm"][1];

	// Set the the indicator for opening the status, profile and photo pages
	// in a new tab to false if the contact a dfrn (friendica) contact
	// We do this because we can go back on foreign friendica pages throuhg
	// friendicas "magic-link" which indicates a friendica user on froreign
	// friendica servers as remote user or visitor
	//
	// The value for opening in a new tab is e.g. when
	// $args["menu"]["status"][2] is true. If the value of the [2] key is true
	// and if it's a friendica contact we set it to false
	foreach ($args["menu"] as $k => $v) {
		if ($k === "status" || $k === "profile" || $k === "photos") {
			$v[2] = (($args["contact"]["network"] === "dfrn") ? false : true);
			$args["menu"][$k][2] = $v[2];
		}
	}

	// Add to pm and poke links a new key with the value 'modal'.
	// Later we can make conditions in the corresponing templates (e.g.
	// contact_template.tpl)
	if (strpos($pokelink, 'poke/?f=&c=' . $cid) !== false) {
		$args["menu"]["poke"][3] = "modal";
	}

	if (strpos($pmlink, 'message/new/' . $cid) !== false) {
		$args["menu"]["pm"][3] = "modal";
	}
}

/**
 * @brief Construct remote nav menu
 *
 *  It creates a remote baseurl form $_SESSION for remote users and friendica
 *  visitors. This url will be added to some of the nav links. With this behaviour
 *  the user will come back to her/his own pages on his/her friendica server.
 *  Not all possible links are available (notifications, administrator, manage,
 *  notes aren't available because we have no way the check remote permissions)..
 *  Some links will point to the local pages because the user would expect
 *  local page (these pages are: search, community, help, apps, directory).
 *
 * @param App $a The App class
 * @param array $nav The original nav menu
 */
function frio_remote_nav($a, &$nav)
{
	// get the homelink from $_XSESSION
	$homelink = Profile::getMyURL();
	if (!$homelink) {
		$homelink = defaults($_SESSION, 'visitor_home', '');
	}

	// split up the url in it's parts (protocol,domain/directory, /profile/, nickname
	// I'm not familiar with regex, so someone might find a better solutionen
	//
	// E.g $homelink = 'https://friendica.domain.com/profile/mickey' should result in an array
	// with 0 => 'https://friendica.domain.com/profile/mickey' 1 => 'https://',
	// 2 => 'friendica.domain.com' 3 => '/profile/' 4 => 'mickey'
	//
	//$server_url = preg_match('/^(https?:\/\/.*?)\/profile\//2', $homelink);
	preg_match('/^(https?:\/\/)?(.*?)(\/profile\/)(.*)/', $homelink, $url_parts);

	// Construct the server url of the visitor. So we could link back to his/her own menu.
	// And construct a webbie (e.g. mickey@friendica.domain.com for the search in gcontact
	// We use the webbie for search in gcontact because we don't know if gcontact table stores
	// the right value if its http or https protocol
	if (count($url_parts)) {
		$server_url = $url_parts[1] . $url_parts[2];
		$webbie = $url_parts[4] . '@' . $url_parts[2];
	}

	// since $userinfo isn't available for the hook we write it to the nav array
	// this isn't optimal because the contact query will be done now twice
	if (local_user()) {
		// empty the server url for local user because we won't need it
		$server_url = '';
		// user info
		$r = q("SELECT `micro` FROM `contact` WHERE `uid` = %d AND `self`", intval($a->user['uid']));

		$r[0]['photo'] = (DBM::is_result($r) ? $a->remove_baseurl($r[0]['micro']) : "images/person-48.jpg");
		$r[0]['name'] = $a->user['username'];
	} elseif (!local_user() && remote_user()) {
		$r = q("SELECT `name`, `nick`, `micro` AS `photo` FROM `contact` WHERE `id` = %d", intval(remote_user()));
		$nav['remote'] = L10n::t("Guest");
	} elseif (Profile::getMyURL()) {
		$r = q("SELECT `name`, `nick`, `photo` FROM `gcontact`
				WHERE `addr` = '%s' AND `network` = 'dfrn'",
			dbesc($webbie));
		$nav['remote'] = L10n::t("Visitor");
	} else {
		$r = false;
	}

	if (DBM::is_result($r)) {
		$nav['userinfo'] = [
			'icon' => (DBM::is_result($r) ? $r[0]['photo'] : "images/person-48.jpg"),
			'name' => $r[0]['name'],
		];
	}

	if (!local_user() && !empty($server_url)) {
		$nav['logout'] = [$server_url . '/logout', L10n::t('Logout'), "", L10n::t('End this session')];

		// user menu
		$nav['usermenu'][] = [$server_url . '/profile/' . $a->user['nickname'], L10n::t('Status'), "", L10n::t('Your posts and conversations')];
		$nav['usermenu'][] = [$server_url . '/profile/' . $a->user['nickname'] . '?tab=profile', L10n::t('Profile'), "", L10n::t('Your profile page')];
		$nav['usermenu'][] = [$server_url . '/photos/' . $a->user['nickname'], L10n::t('Photos'), "", L10n::t('Your photos')];
		$nav['usermenu'][] = [$server_url . '/videos/' . $a->user['nickname'], L10n::t('Videos'), "", L10n::t('Your videos')];
		$nav['usermenu'][] = [$server_url . '/events/', L10n::t('Events'), "", L10n::t('Your events')];

		// navbar links
		$nav['network'] = [$server_url . '/network', L10n::t('Network'), "", L10n::t('Conversations from your friends')];
		$nav['events'] = [$server_url . '/events', L10n::t('Events'), "", L10n::t('Events and Calendar')];
		$nav['messages'] = [$server_url . '/message', L10n::t('Messages'), "", L10n::t('Private mail')];
		$nav['settings'] = [$server_url . '/settings', L10n::t('Settings'), "", L10n::t('Account settings')];
		$nav['contacts'] = [$server_url . '/contacts', L10n::t('Contacts'), "", L10n::t('Manage/edit friends and contacts')];
		$nav['sitename'] = $a->config['sitename'];
	}
}

/**
 * @brief: Search for contacts
 *
 * This function search for a users contacts. The code is copied from contact search
 * in /mod/contacts.php. With this function the contacts will permitted to acl_lookup()
 * and can grabbed as json. For this we use the type="r". This is usful to to let js
 * grab the contact data.
 * We use this to give the data to textcomplete and have a filter function at the
 * contact page.
 *
 * @param App $a The app data @TODO Unused
 * @param array $results The array with the originals from acl_lookup()
 */
function frio_acl_lookup(App $a, &$results)
{
	require_once 'mod/contacts.php';

	$nets = x($_GET, "nets") ? notags(trim($_GET["nets"])) : "";

	// we introduce a new search type, r should do the same query like it's
	// done in /mod/contacts for connections
	if ($results["type"] !== "r") {
		return;
	}

	$searching = false;
	if ($results["search"]) {
		$search_txt = dbesc(protect_sprintf(preg_quote($results["search"])));
		$searching = true;
	}
	
	$sql_extra = '';
	if ($searching) {
		$sql_extra .= " AND (`attag` LIKE '%%" . dbesc($search_txt) . "%%' OR `name` LIKE '%%" . dbesc($search_txt) . "%%' OR `nick` LIKE '%%" . dbesc($search_txt) . "%%') ";
	}

	if ($nets) {
		$sql_extra .= sprintf(" AND network = '%s' ", dbesc($nets));
	}

	$r = q("SELECT COUNT(*) AS `total` FROM `contact`
		WHERE `uid` = %d AND NOT `self` AND NOT `pending` $sql_extra ", intval($_SESSION['uid']));
	if (DBM::is_result($r)) {
		$total = $r[0]["total"];
	}

	$sql_extra3 = Widget::unavailableNetworks();

	$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND NOT `self` AND NOT `pending` $sql_extra $sql_extra3 ORDER BY `name` ASC LIMIT %d, %d ",
		intval($_SESSION['uid']), intval($results['start']), intval($results['count'])
	);

	$contacts = [];

	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			$contacts[] = _contact_detail_for_template($rr);
		}
	}

	$results["items"] = $contacts;
	$results["tot"] = $total;
}

/**
 * @brief Manipulate the data of the item
 *
 * At the moment we use this function to add some own stuff to the item menu
 *
 * @param App $a App $a The app data
 * @param array $arr Array with the item and the item actions<br>
 *     'item' => Array with item data<br>
 *     'output' => Array with item actions<br>
 */
function frio_display_item(App $a, &$arr)
{
	// Add subthread to the item menu
	$subthread = [];
	if (local_user() == $arr['item']['uid'] && $arr['item']['parent'] == $arr['item']['id'] && !$arr['item']['self']) {
		$subthread = [
			'menu'   => 'follow_thread',
			'title'  => L10n::t('Follow Thread'),
			'action' => 'dosubthread(' . $arr['item']['id'] . '); return false;',
			'href'   => '#'
		];
	}
	$arr['output']['subthread'] = $subthread;
}
