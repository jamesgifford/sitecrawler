<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Site;
use App\Jobs\LinkWorker;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Classes\HTMLParser;

class CrawlController extends Controller
{
    public function index()
    {
        $sites = Site::all();

        foreach ($sites as $site) {
            dispatch(new LinkWorker($site));

            $site->updated_at = date('Y-m-d H:i:s');
            $site->update();
        }

        echo 'Done!';
    }
}
