<?php

namespace App\Jobs;

use App\Jobs\Job;
use App\Models\Directory;
use App\Models\Email;
use App\Models\Link;
use App\Models\Site;
use Config;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class LinkWorker extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $site;
    protected $url;
    protected $count;
    protected $userAgent;
    protected $depth;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Site $site, $url = null, $depth = 0, $count = 0)
    {
        $this->site = $site;

        $url = $url == null ? $site->url : $url;
        $this->url = rtrim(strtok($url, '?'), '/');
        $this->count = $count;
        $this->userAgent = Config::get('crawl.userAgent');
        $this->depth = $depth;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /* Validate the url
        ---------------------------------------------------------------------*/

        $url = $this->url;

        // Split out parts of the url for easier examination
        $parsedURL = parse_url($url);
        $urlBase = (isset($parsedURL['scheme']) ? $parsedURL['scheme'] : '').'://'.(isset($parsedURL['host']) ? $parsedURL['host'] : '');
        $urlPath = isset($parsedURL['path']) ? $parsedURL['path'] : '';
        $urlExtension = pathinfo($urlPath, PATHINFO_EXTENSION);
        $urlType = $urlExtension ? 'file' : 'page';

        // Check if the url contains an excluded extension (eg: css files)
        if (in_array($urlExtension, Config::get('crawl.excludeFileExtensions'))) {
            return;
        }

        // Check if the current depth is within tolerance
        if ($this->depth > Config::get('crawl.maxDepth')) {
            'Too deep<br />';
            return;
        }

        $link = Link::where('url', $url)->first();

        // If the url is already in the database, there's nothing else to do
        if ($link !== null) {
            return;
        }

        /* Validate the url content
        ---------------------------------------------------------------------*/

        // Get the page content of the url
        $urlContent = $this->getContent($url);

        // If the url has no content there nothing else to do with it
        if (!$urlContent) {
            return;
        }

        // If the url leads to a 404 or otherwise missing page, move on
        $pattern = "/<title>(.*?)<\/title>/si";
        preg_match($pattern, $urlContent, $pageTitleMatch);
        $pageTitle = strtolower(isset($pageTitleMatch[1]) ? $pageTitleMatch[1] : '');

        if (strpos($pageTitle, '404') !== false || 
            strpos($pageTitle, 'page not found') !== false || 
            strpos($pageTitle, 'bad request') !== false) {
            return;
        }

        /* Save the url to the database
        ---------------------------------------------------------------------*/

        // Store a new link record in the database
        $link = new Link();
        $link->site_id = $this->site->id;
        $link->url = $url;
        $link->extension = $urlExtension;
        $link->save();

        /* Check the parts of the url for readable directories
        ---------------------------------------------------------------------*/

        $dir = $url;

        while ($dir && strpos($dir, '/') !== false) {
            $nextDir = strtolower(rtrim(substr($dir, 0, strrpos($dir, '/')), '/'));

            $directory = Directory::where('url', $dir)->first();

            // If the dir is already in the database, there's nothing else to do
            if ($directory !== null) {
                $dir = $nextDir;
                continue;
            }

            $handle = @fopen($dir, "r");

            // If the path could not be opened then its not a readable directory
            if (!$handle) {
                $dir = $nextDir;
                continue;
            }

            $contents = stream_get_contents($handle);

            @fclose($handle);

            // If the path has no contents then it is not a readable directory
            if (!$contents) {
                $dir = $nextDir;
                continue;
            }

            // If the contents do not contain a list of files then it is not a readable directory
            if (strpos($contents, '<title>Index of /') === false) {
                $dir = $nextDir;
                continue;
            }

            // Store the directory in the database
            $directory = new Directory();
            $directory->site_id = $this->site->id;
            $directory->url = $dir;
            $directory->save();

            $dir = $nextDir;
        }

        /* Parse the page content for email addresses
        ---------------------------------------------------------------------*/

        // Get all the email addresses in the current page content
        $emails = $this->getEmails($urlContent, $url);

        if ($emails) {
            foreach ($emails as $emailAddress) {
                $emailAddress = strtolower($emailAddress);

                $email = Email::where('address', $emailAddress)->first();

                // If the email is already in the database, there's nothing else to do
                if ($email !== null) {
                    continue;
                }

                // Store a new email record in the database
                $email = new Email();
                $email->site_id = $this->site->id;
                $email->address = $emailAddress;
                $email->save();
            }
        }

        /* Parse the page content for additional urls
        ---------------------------------------------------------------------*/

        // Get all the urls within the current page content
        $links = $this->getLinks($urlContent, $url);

        // If there are no links found, no need to continue with this url
        if (!count($links)) {
            return;
        }

        foreach ($links as $link) {
            $link = rtrim(strtok($link, '?'), '/');

            if ($link == $url) {
                continue;
            }

            dispatch(new LinkWorker($this->site, $link, $this->depth + 1, ++$this->count));
        }

        return;
    }

    /**
     * Load an html page
     * param   string  the url of the page to load
     * return  bool
     */
    protected function getContent($url)
    {
        // If there is no valid currentURL then no HTML can be parsed
        if (!$url) {
            return false;
        }

        $url = rtrim($url, '/');
        $url .= '/';

        // Try loading the page the easy way first
        try {
            $contents = @file_get_contents($url);
        } 
        catch (Exception $e) {
            return;
        }
        
        if ($contents) {
            return $contents;
        }

        $urlParts = parse_url($url);
        $path = $urlParts['path'];
        $host = $urlParts['host'];

        if (isset($urlParts['query']) && $urlParts['query'] != "") {
            $path .= "?".$urlParts['query'];
        }

        if (isset($urlParts['port'])) {
            $port = (int)$urlParts['port'];
        }
        else {
            if ($urlParts['scheme'] == "http") {
                $port = 80;
            } 
            else {
                if ($urlParts['scheme'] == "https") {
                    $port = 443;
                }
            }
        }

        if ($port == 80) {
            $portString = "";
        } else {
            $portString = ":$port";
        }

        $all = "*/*";

        // Build the request for the page
        $request = "GET $path HTTP/1.0\r\nHost: $host$portString\r\nAccept: $all\r\nUser-Agent: $this->userAgent\r\n\r\n";

        $fsocketTimeout = 30;
        if (substr($url, 0, 5) == "https") {
            $target = "ssl://".$host;
        } else {
            $target = $host;
        }

        $errno = 0;
        $errstr = "";

        try {
            $fp = @fsockopen($target, $port, $errno, $errstr, $fsocketTimeout);
        } catch (Exception $e) {
            return;
        }

        if (!$fp) {
            return false;        
        }
        else {
            if (!fputs($fp, $request)) {
                return false;
            }

            $data = null;
            socket_set_timeout($fp, $fsocketTimeout);
            
            do {
                $status = socket_get_status($fp);
                $data .= fgets($fp, 8192);
            } while (!feof($fp) && !$status['timed_out']) ;

            fclose($fp);

            if ($status['timed_out'] == 1) {
                $contents['state'] = "timeout";
            }
            else {
                $contents['state'] = "ok";
            }

            if ($contents['state'] == "ok") {
                return substr($data, strpos($data, "\r\n\r\n") + 4);
            }
        }

        return false;
    }

    /**
     * Find all links in the current page
     * return  array
     */
    protected function getLinks($content, $url = '')
    {
        $parsedURL = parse_url($url);
        $urlBase = (isset($parsedURL['scheme']) ? $parsedURL['scheme'] : '').'://'.(isset($parsedURL['host']) ? $parsedURL['host'] : '');

        $urlPattern = "/[href|HREF]\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        //$urlPattern = '/<a href="(.+)">/';
        preg_match_all($urlPattern, $content, $urlMatches);

        $links = array();
        foreach ($urlMatches[1] as $link) {
            $link = strtolower(strtok($link, '?'));

            if (!$link || $link == '/' || in_array($link, $links)) {
                continue;
            }

            if ($url) {
                if (strpos($link, '/') === 0) {
                    $link = $urlBase . $link;
                }

                if (strrpos($link, $urlBase) !== 0) {
                    continue;
                }
            }

            $link = rtrim($link, '/');

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Find all links in the current page
     * return  array
     */
    protected function getEmails($content, $url = '')
    {
        $emails = array();
        $emailPattern = '`\<a([^>]+)href\=\"mailto\:([^">]+)\"([^>]*)\>(.*?)\<\/a\>`ism';
        preg_match_all($emailPattern, $content, $emailMatches);

        if (!isset($emailMatches[2]) || !is_array($emailMatches[2])) {
            return $emails;
        }

        foreach ($emailMatches[2] as $email) {
            if (!$email || $email == '/') {
                continue;
            }

            if (strpos($email, '@') === false) {
                continue;
            }

            $email = strtolower(rtrim($email, '/'));

            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * Find all directory links in the current page
     * return  array
     */
    protected function getDirectories($content, $url = '')
    {
        //$urlPattern = "/[href|HREF]\s*=\s*[\'\"]?([+:%\/\?~=&;\\\(\),._a-zA-Z0-9-]*)(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i";
        $urlPattern = '/<a href="([a-zA-Z0-9\-\_\/\.]+)">/';
        preg_match_all($urlPattern, $content, $urlMatches);

        $links = array();
        foreach ($urlMatches[1] as $link) {
            if (!$link || $link == '/') {
                continue;
            }

            $link = strtolower(rtrim(trim($link, '/'), '/'));
            $link = ($url . '/' . $link);

            $links[] = $link;
        }

        return $links;
    }
}
