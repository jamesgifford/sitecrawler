<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Classes\HTMLParser;

class CrawlController extends Controller
{
    public function index()
    {
        $url = 'http://www.amnhealthcare.com/';
        $links = [];

        $html = new HTMLParser([
            'domain' => $url,
            'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36',
        ]);

        $result = $html->recursiveFunction($url, $links);

        print_r($links);

        //$html->loadPage('http://www.amnhealthcare.com/');

        //$links = $html->getLinks();

        //print_r($links);
    }
}
