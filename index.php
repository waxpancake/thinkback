<?php
// don't forget to set your config in config.inc.php
include 'config.inc.php';
date_default_timezone_set($timezone);

// this shouldn't have to change
$tu_api_endpoint = '/api/v1/post.php';

if ($_GET['user']) { $username = $_GET['user']; }

// template setup
include "inc/rain.tpl.class.php";
raintpl::configure("tpl_dir", "tpl/" );
raintpl::configure("tmp_dir", "tmp/" );

$tpl = new RainTPL;
$tpl->configure('path_replace_list', array('link', 'script') );

if ( $cache = $tpl->cache('page', $expire_time = $cache_in_seconds) ) {
	echo $cache;
} else {
    $args = array(
        type => 'user_posts',
        username => $username,
        count => 1,
        order_by => 'date',
        direction => 'asc'
    );
    $results = ThinkupQuery($args);
    
    if ($results->error or count($results) == 0) {
        exit("Sorry, I couldn't find your archives in ThinkUp. Please check your username in config.inc.php settings and try again.");
    }
    $first_tweet = array_shift($results);
    $user_info = $first_tweet->user;
    
    $today = date('Y-m-01');
    $date_start = date('Y-m-01', strtotime( $first_tweet->created_at ));
    $date_end = date('Y-m-01', strtotime( '+1 month', strtotime($date_start)) );

    $keywords = array();
    $stats = array();
    $aggregate_counts = array();
    
    while ($date_start < $today) {
        $args = array(
            type => 'user_posts_in_range',
            username => $username,
            trim_user => 1,
            from => $date_start,
            until => $date_end,
            count => 10000
        );
        $results = ThinkupQuery($args);
        
        // track the number of tweets per month
        $num_tweets = count($results);

        if ($num_tweets == 0) {
            $date_start = date('Y-m-01', strtotime( '+1 month', strtotime($date_start)) );
            $date_end = date('Y-m-01', strtotime( '+1 month', strtotime($date_start)) );
            continue;
        }

        $stats[$date_start] = $num_tweets;
        
        $text = '';
        foreach ($results as $post) {
            // exclude replies and retweets
            if ( preg_match("/^(RT|\@)/", $post->text) ) {
                continue;
            } else {
                $text .= $post->text . "  ";
            }
        }

        $response = AlchemyQuery($text);
        
        $keywords[$date_start] = array();
        $i = 0;
        foreach ($response->entities as $entity) {
            $keyword = $entity->text;
            $keyword = preg_replace("/\.$/", '', $keyword);
            $keywords[$date_start][$keyword] = $entity->type;
            if ( $i >= $max_items ) { break; } else { $i++; }
        }
        
        // increment dates
        $date_start = date('Y-m-01', strtotime( '+1 month', strtotime($date_start)) );
        $date_end = date('Y-m-01', strtotime( '+1 month', strtotime($date_start)) );
    }

    // using Text-Processing.com's API (disabled)
    # $response = TextProcessingQuery($text);
    # print_r($response) . "<br><br>";
    # $places = $response->FACILITY;
    # print_r($places);
    
    // using Zemanta.com's API (disabled)
    # $response = ZemantaQuery($text);
    # list($keywords, $images) = ZemantaExtract($response);
    
    $max_messages = max($stats);
    $colors = array(
        'Person' => '#B35806', 
        'Organization' => '#E08214',
        'Company' => '#FDB863',
        'Technology' => '#FEE0B6',
        'OperatingSystem' => '#FEE0B6',
        'Product' => '#B2ABD2',
        'City' => '#8073AC',
        'StateOrCounty' => '#8073AC',
        'Country' => '#8073AC',
        'Continent' => '#8073AC',
        'Facility' => '#8073AC',
        'Terminology' => '#542788',
        'Other' => '#999'
    );

    $tpl->assign('colors', $colors);
    $tpl->assign('max_messages', $max_messages);    
    $tpl->assign('keywords', $keywords);
    $tpl->assign('stats', $stats);
    $tpl->assign('user_info', $user_info);

    $tpl->draw('page');


}



function ThinkupQuery($args) {
    $query = '?' . http_build_query($args);
    $url = $GLOBALS['tu_install'] . $GLOBALS['tu_api_endpoint'] . $query;
    # error_log($url);

    // if request fails, try up to five times
    for ($i=0; $i < 5; $i++) {
        if ( $contents = file_get_contents($url) ) {
            break;
        }
    }

    $contents = utf8_encode($contents);
    $json = json_decode($contents);
    return $json;
}

function ZemantaQuery($text) {
    // Zemanta does entity extraction and analysis
    $url = 'http://api.zemanta.com/services/rest/0.0/';
    $format = 'json'; 
    $key = $GLOBALS['zemanta_api_key']; 
    $method = "zemanta.suggest"; 
    
    $args = array(
        'method'=> $method,
        'api_key'=> $key,
        'text'=> $text,
        'format'=> $format
    );
    
    $data = "";
    foreach($args as $key=>$value) {
        $data .= ($data != "")?"&":"";
        $data .= urlencode($key)."=".urlencode($value);
    }
    
    $params = array('http' => array(
        'method' => 'POST',
        'Content-type'=> 'application/x-www-form-urlencoded',
        'Content-length' =>strlen( $data ),
        'content' => $data
    ));

    /* Here we send the post request */
    $ctx = stream_context_create($params); // We build the POST context of the request
    $fp = @fopen($url, 'rb', false, $ctx); // We open a stream and send the request
    if ($fp) {
        $response = @stream_get_contents($fp);
        if ($response === false) {
            $error = "Problem reading data from ".$url.", ".$php_errormsg;
        }
        fclose($fp); // We close the stream
    } else {
        $error = "Problem reading data from ".$url.", ".$php_errormsg;
    }

    if ($error) {
        print $error;
        return $error;
    }
    $response = utf8_encode($response);
    $json = json_decode($response);
    return $json;

}

function ZemantaExtract($response) {
    $keywords = array();
    foreach ($response->keywords as $keyword) {
        array_push($keywords, $keyword->name);
    }
    
    $images = array();
    foreach ($response->images as $image) {
        array_push($images, $image->url_m);
        print '<img src="' . $image->url_m . '">';
    }
    
    return array($keywords, $images);
    
}


function AlchemyQuery($text) {
    $url = 'http://access.alchemyapi.com/calls/text/TextGetRankedNamedEntities';
    $format = 'json'; // May depend of your application context
    
    $args = array(
        'method'=> $method,
        'apikey'=> $GLOBALS['alchemy_api_key'],
        'text'=> $text,
        'outputMode'=> $format,
        'disambiguate'=> 0,
        'linkedData'=> 0,
    );
    
    $data = "";
    foreach($args as $key=>$value) {
        $data .= ($data != "")?"&":"";
        $data .= urlencode($key)."=".urlencode($value);
    }
    
    $params = array('http' => array(
        'method' => 'POST',
        'Content-type'=> 'application/x-www-form-urlencoded',
        'Content-length' => strlen($data),
        'content' => $data
    ));

    /* Here we send the post request */
    $ctx = stream_context_create($params); 
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp) {
        $response = @stream_get_contents($fp);
        if ($response === false) {
            $error = "Problem reading data from ".$url.", ".$php_errormsg;
        }
        fclose($fp); // We close the stream
    } else {
        $error = "Problem reading data from ".$url.", ".$php_errormsg;
    }

    if ($error) {
        print $error;
        return $error;
    }
    $response = utf8_encode($response);
    $json = json_decode($response);
    return $json;

}


function TextProcessingQuery($text) {
    $url = 'http://text-processing.com/api/phrases/';
    $format = 'json';
    
    $args = array(
        'text'=> $text,
    );
    
    $data = "";
    foreach($args as $key=>$value) {
        $data .= ($data != "")?"&":"";
        $data .= urlencode($key)."=".urlencode($value);
    }
    
    $params = array('http' => array(
        'method' => 'POST',
        'Content-type'=> 'application/x-www-form-urlencoded',
        'Content-length' =>strlen( $data ),
        'content' => $data
    ));

    $ctx = stream_context_create($params);
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp) {
        $response = @stream_get_contents($fp);
        if ($response === false) {
            $error = "Problem reading data from ".$url.", ".$php_errormsg;
        }
        fclose($fp); // We close the stream
    } else {
        $error = "Problem reading data from ".$url.", ".$php_errormsg;
    }

    if ($error) {
        print $error;
        return $error;
    }
    $response = utf8_encode($response);
    $json = json_decode($response);
    return $json;

}