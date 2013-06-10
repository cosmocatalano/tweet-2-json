<?php
/*
tweet2json — the Twitter scrape API
by Cosmo Catalano - http://cosmocatalano.com

SET THE OUTPUT OF THIS SCRIPT AT THE END OF THE FILE
LINE 276
*/


ini_set('default_charset', 'utf-8');

//Uncomment for a nice, clean, plaintext response
//header('Content-type: text/plain');

//here are the strings this script uses to find the data it wants. 
//Twitter might change them—they're up here so you can update & repair easily.
$finders = array(
    'onebox-find'			 =>	    '/<div class="onebox[\s\S]*<h2 class="/U',
    'onebox-replace' 		 =>		'<h2 class="',
	'content-explode-top'    => 	' tweet-text',    //relies on another class being assigned before it. very sketchy.
	'content-explode-bottom' => 	'</p>',
	'avatar-explode-top'	 =>		'class="account-group', //actually, any explode scraping is kinda sketch
	'avatar-explode-bottom'  =>		'<strong',
	
	'avatar-regex'           => 	array(
										'user'   => '/href\="([\/A-z0-9-_]*)/',
										'id'     => '/data-user-id\="([0-9-_]*)/',
										'img'    => '/src\="([A-z0-9\-\_\:\/\/\.]*)/',
										'name'   => '/alt\="([A-z0-9\-\_\:\/\/\.\s]*)/'
									),
									
	'links-regex'			 => 	'/<a class="details with-icn js-details" href="([\/A-z0-9]*)">/',
	'dates-regex'       	 => 	'/data\-time\="([0-9]*)"/',
	'cards-regex'			 => 	'/media-thumbnail[^&][\s\SA-z0-9\"\=\-\:\/\?\&\;\_]*>/U',
	'video-regex'			 =>		'/<iframe class="card2-player-iframe"[\s\S]*<\/h3>/',
	
	'cards-data' 			 => 	array(
										'href'   => 'href',
										'src'    => 'data-url',
										'src-lg' => 'data-resolved-url-large'
									),
	'video-data' 			 => 	array(
										'iframe'   => '/(<iframe class="card2-player-iframe"[\s\S]*<\/iframe>)/',
										'href'    => '/href="([\S]*)"/',
									)							
);

//removes content-less onebox that fools avatar search
function kill_onebox ($source) {
	global $finders;
	$no_onebox = preg_replace($finders['onebox-find'], $finders['onebox-replace'], $source);
	return $no_onebox;
}
	

//breaks the page into chunks of containing to tweets & data, more or less			
function tweet_content($source, $itr) {
	global $finders;
	$scrubs  = array(
			0 => '/<\/?[sb]>/',
			1 => '/href="\//',
		);
	$ringers = array(
			0 => '',
			1 => 'href="http://twitter.com/',
		);
	$shards = explode($finders['content-explode-top'], $source);
	$tweets = array();
	if ($itr == FALSE) {
		$itr = count($shards);
	}
	for ($i = 1;  $i <= $itr; $i++) {
		$dirty_tweet = explode($finders['content-explode-bottom'], $shards[$i]);
		$clean_tweet = ltrim($dirty_tweet[0],'">' );
		$replaced = preg_replace($scrubs, $ringers, $clean_tweet);
		array_push($tweets, $replaced);
	}
	return $tweets;
}

//This pulls avatar src, username (of tweeter), name, id
function tweet_avatar($source, $itr) {
	global $finders;
	$patterns = $finders['avatar-regex'];
	$shards = explode($finders['avatar-explode-top'], $source);
	$avatars = array();
	if ($itr == FALSE) {
		$itr = count($shards);
	}
	for ($i = 1;  $i <= $itr; $i++) {
		$dirty_avatar = explode($finders['avatar-explode-bottom'], $shards[$i]);
		array_push($avatars, $dirty_avatar[0]);
	}
	$clean_data = array();
	foreach ($avatars as $avatar) {
		$avatar_data = array();
		foreach($patterns as $pattern) {
			preg_match($pattern, $avatar, $matches);
			array_push($avatar_data, $matches[1]);
		}
		array_push($clean_data, $avatar_data);
	}
	return $clean_data;			
}

// pulls the links from a tweet
function tweet_links($source) {
	global $finders;
	preg_match_all($finders['links-regex'], $source, $links);
	return $links[1];
}

//pulls the timestamps from a tweet
function unix_dates($source) {
	global $finders;
	preg_match_all($finders['dates-regex'], $source, $timestamps);
	return($timestamps[1]);
}

//pulls any twitter "cards" from tweets, including making up fake ones out of instagram
function get_cards($url) {
	global $finders;
	$source = file_get_contents($url);
	$pattern = $finders['cards-regex'];
	preg_match($pattern, $source, $matches);
	
//looking for a youtube/vine/video generally link
	if ($matches === array()) {
		$vid_pattern = $finders['video-regex'];
		preg_match($vid_pattern, $source, $vid_matches);
		if ($vid_matches !== array()) {
			$needles = $finders['video-data'];
			$card_data = array();
			foreach ($needles as $eye => $needle) {
				preg_match($needle, $vid_matches[0], $vid_cards);
				$card_data[$eye] = $vid_cards[1];
			}
			return $card_data;
		}
	}	
	

//if no matches are found, look for an instagram link
	if ($vid_matches === array()) {
		$pattern = '/instagram\.com\/p\/[A-z0-9\_\-]*\/?/';    //Instagram might change their URL structure sometime, requiring an update to this
		preg_match($pattern, $source, $ig_matches);
		

//if an instagram link is found, suck its data into the $matches array
		if ($ig_matches !== array()) {
			$ig_url = 'http://'.$ig_matches[0];
			$ig_source = file_get_contents($ig_url);
			$ig_pattern = '/<meta property="og:image" content="http:\/\/([a-z0-9\.\/\_]*)"/';  //Also unlikely, but this might change too
			preg_match($ig_pattern, $ig_source, $src_matches);

//matching the Twitter classes for cards. Kept the same class assignments, but it's not necessary.
			$matches['href'] = $ig_url;
			$matches['data-url'] = 'http://'.str_replace('_7.jpg', '_5.jpg', $src_matches[1]);
			$matches['data-resolved-url-large'] = 'http://'.$src_matches[1];
			$card_data = $matches;

//if nothing is found, set $matches to 0
		}else{
			$card_data = 0;
		}	
				
//returning to a situation where standard twitter card matches are found	
	}else{
		$targets = $finders['cards-data']; 
		$card_data = array();
		foreach($targets as $target) {
			preg_match('/'.$target.'="([\S]*)"/', $matches[0], $card_attr); //this shouldn't need to change
			$card_data[$target] = $card_attr[1];
		}
	}
	return $card_data;	
}

function scrape_spit ($user_target, $search, $find_cards, $itr, $realtime = FALSE) {
//cleaning up user inputs
	if ($search === '') {
		$dirty_target = str_replace('@', '', $user_target);
	} else {
		$search = 'search/';
		$dirty_target = $user_target;
	}
	if ($realtime == TRUE) {
		$search = 'search/realtime/';
		$dirty_target = $user_target;
	}
	$target = urlencode($dirty_target);

//initial scrape
	
	$onebox_source = file_get_contents("http://twitter.com/".$search.$target);
	$source = kill_onebox($onebox_source);

//re-organizing the data with functions
	$avatars = tweet_avatar($source, $itr);
	$tweets = tweet_content($source, $itr);
	$links = tweet_links($source, $itr);
	$dates = unix_dates($source);
	
//some characters that need attention
	$html_scrubs = array('&nbsp;','&#39;','&quot;', '&lt;', '&rt;');
	$html_ringers = array(    ' ',    "'",     '"',    '<',  '>');
	
//Checking user preferences on how much data to send back
	$all_tweets = array();
	if ($itr == FALSE) {
		$real_itr = count($tweets) - 1;
	}else{
		$real_itr = $itr;
	}
	
//Checking for RTs
	for ($i = 0; $i < $real_itr; $i++) {  
		if ($search === '' AND '/'.strtolower($target) === strtolower($avatars[$i][0])) {
			$is_rt = FALSE;
		}elseif ($search !== '') {
			$is_rt = FALSE;
		}else{
			$is_rt = TRUE;
		}
		
//creating the return array for each tweet
		$each_tweet = array(
			'url'	 => 'http://twitter.com'.$links[$i],
			'text'   => html_entity_decode(str_replace($html_scrubs, $html_ringers, strip_tags($tweets[$i]))),
			'html'  => $tweets[$i],
			'date' 	 => $dates[$i],
			'user'   => $avatars[$i][0],
			'id'     => $avatars[$i][1],
			'img'    => $avatars[$i][2],
			'name'   => $avatars[$i][3],
			'rt'     => $is_rt,
		);
	
//because searching for cards takes FOREVER
		if ($find_cards != FALSE) {
			$card = get_cards('http://twitter.com'.$links[$i]);
			if ($card !== 0) {
				$each_tweet['card'] = $card;
			}else{
				unset($each_tweet['card']);
			}
		}
		array_push($all_tweets, $each_tweet);
	}

	return $all_tweets;
}

//formats output in nice JSON for you
function return_json($array) {
	$new_array = array('tweets' => $array);
	$return = json_encode($new_array);
	return $return;
}

//These are the functions the user should actually call

function user_tweets($username, $itr = 0, $find_cards = FALSE) {
	$search = '';
	$return = scrape_spit($username, $search, $find_cards, $itr);
	return return_json($return);
}
	
function search_tweets($query, $itr = 0, $find_cards = FALSE, $realtime = TRUE) {
	$search = 'search';
	$return = scrape_spit($query, $search, $find_cards, $itr, $realtime);
	return return_json($return);
}

/*THIS IS WHERE YOU MAKE EDITS TO CHANGE WHAT THIS SCRIPT RETURNS*/

echo user_tweets('cosmocatalano', 1);

//echo search_tweets('obama');


?>