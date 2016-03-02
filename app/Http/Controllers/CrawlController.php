<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Site;
use App\Jobs\CrawlSite;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Classes\HTMLParser;

class CrawlController extends Controller
{
    public function index()
    {
        $sites = Site::all();

        foreach ($sites as $site) {
            $job = (new CrawlSite($site));
            dispatch($job);
        }
    }
}
