<?php
include '../wp-config.php';
require_once ("vendor/autoload.php");

$username = 'admin';
$password = 'admin';

function getLastPost($cat_id)
{
    $args = array(
        'numberposts' => 1,
        'category' => $cat_id,
        'post_status' => 'publish',
    );
    $result = wp_get_recent_posts($args);
    foreach ($result as $post)
    {
        $last_post_title = $post['post_title'];
    }

    return $last_post_title;

}

function postImg($path)
{

    global $username;
    global $password;

    $url = 'http://localhost/parser/wp-json/wp/v2/media/';

    if (strlen($path) > 0)
    {
        $file = file_get_contents($path);
    }
    else
    {
        $file = file_get_contents("http://localhost/parser/wp-content/uploads/2020/07/no_image");
    }

    // $imageType = $path;
    // $headers = get_headers($imageType, 1);
    // echo $headers['Content-Type'];
    // доделать разные форматы
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $file);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Disposition: form-data; filename="' . basename($path) . '.jpg"', 'Authorization: Basic ' . base64_encode($username . ':' . $password) , ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (preg_match('|"id":(.*?),|sei', $result, $arr))
    { // execute id from string
        $imgId = $arr[1];
    }

    return $imgId;

}

function postArticle($title, $article, $attach_id, $strip_content, $article_date, $cat_id)
{
    global $username;
    global $password;

    $api_response_post = wp_remote_post('http://localhost/parser/wp-json/wp/v2/posts', array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ) ,
        'body' => array(
            'title' => $title,
            'status' => 'publish',
            'content' => $article,
            'categories' => $cat_id, // category ID
            'featured_media' => $attach_id,
            'date' => $article_date,

            'excerpt' => substr($strip_content, 0, 300) ,
            // developer.wordpress.org/rest-api/reference/posts/#create-a-post
            
        )
    ));

    $body = json_decode($api_response_post['body']);

    if (wp_remote_retrieve_response_message($api_response) === 'Created')
    {
        echo 'The post ' . $body
            ->title->rendered . ' has been created successfully';
    }

}

function del_tags($txt, $tag)
{
    $tags = explode(',', $tag);
    do
    {
        $tag = array_shift($tags);
        $txt = preg_replace("~<($tag)[^>]*>|(?:</(?1)>)|<$tag\s?/?>~x", '', $txt);
    }
    while (!empty($tags));
    return $txt;
}

//https://finance.yahoo.com/rss/
function Main_parser($rss, $cat_id)
{

    $last_post_title = getLastPost($cat_id);

    $xml = simplexml_load_file($rss);

    $sorted_xml = array();

    foreach ($xml->xpath('//item') as $item)
    {
        $sorted_xml[] = $item;
    }

    usort($sorted_xml, function ($a, $b)
    { // sort by date
        return strtotime($b->pubDate) - strtotime($a->pubDate);
    });

    foreach ($sorted_xml as $item)
    {
        echo '<li>' . $item->title . '</li>';
        echo '<li>' . $item->pubDate . '</li>';

        //$date = $format=str_replace('-0400','',$item->pubDate);
        $date = date_create($item->pubDate);
        $article_date = date_format($date, "Y-m-d H:i:s");

        echo '<li>' . $article_date . '</li>';

        preg_match_all('/<img[^>]+>/i', $item->description, $getImage);
        preg_match_all('/<img[^>]*?src=\"(.*)\"/iU', $getImage[0][0], $getImageUrl);
        preg_replace('#https?://(.*?) #i', $item->description, $clearDesc);

        $bigImgUrl = stristr($getImageUrl[1][0], 'https://media.zenfs.com');
        $data_link = file_get_contents($item->link);
        $document_с = phpQuery::newDocument($data_link);

        $article = $document_с->find('article');
        $article = preg_replace('/content=".*?"/', '', $article);
        $article = preg_replace('/class=".*?"/', '', $article);
        $article = preg_replace('/type=".*?"/', '', $article);
        $article = preg_replace('/itemprop=".*?"/', '', $article);
        $article = preg_replace('/data-uuid=".*?"/', '', $article);
        $article = preg_replace('/data-reactid=".*?"/', '', $article);
        $article = preg_replace('/title=".*?"/', '', $article);
        $article = preg_replace('/style=".*?"/', '', $article);

        $title = del_tags($item->title, 'a');
        $content = del_tags($item->description, 'a, img');
        $strip_content = strip_tags($content, '<br>');
        $attach_id = postImg($bigImgUrl);

        if ($title == $last_post_title)
        {
            break;
        }

        postArticle($title, $article, $attach_id, $strip_content, $article_date, $cat_id);

    }

}

