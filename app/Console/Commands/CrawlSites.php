<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSite;
use App\Models\Site;
use Illuminate\Console\Command;

class CrawlSites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index sites in the sites table';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $sites = Site::all();

        foreach ($sites as $site) {
            dispatch(new LinkWorker($site));
        }
    }
}
