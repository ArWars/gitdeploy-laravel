<?php

namespace ArWars\GitDeploy\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

use Illuminate\Support\Facades\Log;

use Artisan;
use Event;

use ArWars\GitDeploy\Events\GitDeployed;

class GitDeployController extends Controller
{
    public function getToken(Request $request, $type)
    {
        switch($type){
            case 'mac':
                $header = $this->header('Authorization', '');
                if (Str::startsWith($header, 'Bearer ')) {
                    return Str::substr($header, 7);
                }
                break;
            case '256':
                $header = $this->header('Authorization', '');
                if (Str::startsWith($header, 'Bearer ')) {
                    return Str::substr($header, 7);
                }
                break;
            default:
                $header = $this->header('Authorization', '');
                if (Str::startsWith($header, 'Bearer ')) {
                    return Str::substr($header, 7);
                }
                break;
        }
    }

    public function gitHook(Request $request)
    {
        $git_path = !empty(config('gitdeploy.git_path')) ? config('gitdeploy.git_path') : 'git';
        $git_remote = !empty(config('gitdeploy.remote')) ? config('gitdeploy.remote') : 'origin';

        // Limit to known servers
        if (!empty(config('gitdeploy.allowed_sources'))) {

            $remote_ip = $this->formatIPAddress($_SERVER['REMOTE_ADDR']);
            $allowed_sources = array_map([$this, 'formatIPAddress'], config('gitdeploy.allowed_sources'));

                if (!in_array($remote_ip, $allowed_sources)) {
                    Log::error('Request must come from an approved IP');
                        return Response::json([
                            'success' => false,
                            'message' => 'Request must come from an approved IP',
                        ], 401);
                }
            }

            // Collect the posted data
            $postdata = json_decode($request->getContent(), TRUE);
            if (empty($postdata)) {
                Log::error('Web hook data does not look valid');
                    return Response::json([
                        'success' => false,
                        'message' => 'Web hook data does not look valid',
                ], 500);
            }

        // Check the config's directory
        $repo_dir = config('gitdeploy.repo_path');
        if (!empty($repo_dir) && !file_exists($repo_dir.'/.git/config')) {
            Log::error('Invalid repo path in config');
            return Response::json([
                'success' => false,
                'message' => 'Invalid repo path in config',
            ], 500);
        }

        // Try to determine Laravel's directory going up paths until we find a .env
        if (empty($repo_dir)) {
            $checked[] = $repo_dir;
            $repo_dir = __DIR__;
            do {
                $repo_dir = dirname($repo_dir);
            } while ($repo_dir !== '/' && !file_exists($repo_dir.'/.env'));
        }

        // This is not necessarily the repo's root so go up more paths if necessary
        if ($repo_dir !== '/') {
            while ($repo_dir !== '/' && !file_exists($repo_dir.'/.git/config')) {
                $repo_dir = dirname($repo_dir);
            }
        }

        // So, do we have something valid?
        if ($repo_dir === '/' || !file_exists($repo_dir.'/.git/config')) {
            Log::error('Could not determine the repo path');
            return Response::json([
                'success' => false,
                'message' => 'Could not determine the repo path',
            ], 500);
        }

        // Check signatures
        if (!empty(config('gitdeploy.secret'))) {
            $header = config('gitdeploy.secret_header');
            $header_data = $request->header($header);

            /**
             * Check for valid header
             */
            if (!$header_data) {
                Log::error('Could not find header with name ' . $header);
                return Response::json([
                    'success' => false,
                    'message' => 'Could not find header with name ' . $header,
                ], 401);
            }
            

            /**
             * Sanity check for key
             */
            if (empty(config('gitdeploy.secret_key'))) {
                Log::error('Secret was set to true but no secret_key specified in config');
                return Response::json([
                    'success' => false,
                    'message' => 'Secret was set to true but no secret_key specified in config',
                ], 500);
            }

            /**
             * Check plain secrets (Gitlab)
             */
            if (config('gitdeploy.secret_type') == 'plain') {
                if ($header_data !== config('gitdeploy.secret_key')) {
                    Log::error('Secret did not match');
                    return Response::json([
                        'success' => false,
                        'message' => 'Secret did not match',
                    ], 401);
                }
            }

            /**
             * Check hmac secrets (Github)
             */
            else if (config('gitdeploy.secret_type') == 'mac') {
                if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
                    return Response::json([
                        'success' => false,
                        'message' => "X-Hub-Signature' is missing.",
                    ], 401);
                } elseif (!extension_loaded('hash')) {
                    return Response::json([
                        'success' => false,
                        'message' => "Missing 'hash' extension to check the secret code validity.",
                    ], 401);
                }
                list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2) + array('', '');
                if (!in_array($algo, hash_algos(), TRUE)) {
                    return Response::json([
                        'success' => false,
                        'message' => "Hash algorithm '$algo' is not supported.",
                    ], 401);
                }
                $rawPost = file_get_contents('php://input');
                if (!hash_equals($hash, hash_hmac($algo, $rawPost, config('gitdeploy.secret_key')))) {
                    return Response::json([
                        'success' => false,
                        'message' => "Hook secret does not match.",
                    ], 401);
                }
            }

            /**
             * Catch all for anything odd in config
             */
            else {
                Log::error('Unsupported secret type');
                return Response::json([
                    'success' => false,
                    'message' => 'Unsupported secret type',
                ], 500);
            }

            // If we get this far then the secret matched, lets go ahead!
        }

        // Get current branch this repository is on
        $cmd = escapeshellcmd($git_path) . ' --git-dir=' . escapeshellarg($repo_dir . '/.git') .  ' --work-tree=' . escapeshellarg($repo_dir) . ' rev-parse --abbrev-ref HEAD';
        $current_branch = trim(exec($cmd)); //Alternativly shell_exec

        // Get branch this webhook is for
        $pushed_branch = explode('/', $postdata['ref']);
        $pushed_branch = trim($pushed_branch[2]);

        // If the refs don't matchthis branch, then no need to do a git pull
        if ($current_branch !== $pushed_branch){
            Log::warning('Pushed refs do not match current branch');
            return Response::json([
                'success' => false,
                'message' => 'Pushed refs do not match current branch',
            ], 500);
        }

        // At this point we're happy everything is OK to pull, lets put Laravel into Maintenance mode.
        if (!empty(config('gitdeploy.maintenance_mode'))) {
            Log::info('Gitdeploy: putting site into maintenance mode');
            Artisan::call('down');
        }

        // git pull
        Log::info('Gitdeploy: Pulling latest code on to server');
        $cmd = escapeshellcmd($git_path) . ' --git-dir=' . escapeshellarg($repo_dir . '/.git') . ' --work-tree=' . escapeshellarg($repo_dir) . ' pull ' . escapeshellarg($git_remote) . ' ' . escapeshellarg($current_branch) . ' > ' . escapeshellarg($repo_dir . '/storage/logs/gitdeploy.log');

        $server_response = [
            'cmd' => $cmd,
            'user' => shell_exec('whoami'),
            'response' => shell_exec($cmd),
        ];

        // Put site back up and end maintenance mode
        if (!empty(config('gitdeploy.maintenance_mode'))) {
            Artisan::call('up');
            Log::info('Gitdeploy: taking site out of maintenance mode');
        }

        // Fire Event that git were deployed
        if (!empty(config('gitdeploy.fire_event'))) {
            event(new GitDeployed($postdata['commits']));
            Log::debug('Gitdeploy: Event GitDeployed fired');
        }

        if (!empty(config('gitdeploy.email_recipients'))) {

            // Humanise the commit log
            foreach ($postdata['commits'] as $commit_key => $commit) {

                // Split message into subject + description (Assumes Git's recommended standard where first line is the main summary)
                $subject = strtok($commit['message'], "\n");
                $description = '';

                // Beautify date
                $date = new \DateTime($commit['timestamp']);
                $date_str = $date->format('d/m/Y, g:ia');

                $postdata['commits'][$commit_key]['human_id'] = substr($commit['id'], 0, 9);
                $postdata['commits'][$commit_key]['human_subject'] = $subject;
                $postdata['commits'][$commit_key]['human_description'] = $description;
                $postdata['commits'][$commit_key]['human_date'] = $date_str;
            }

            // Standardise formats for Gitlab / Github payload differences
            if (isset($postdata['pusher']) && !empty($postdata['pusher'])) {
                $postdata['user_name'] = $postdata['pusher']['name'];
                $postdata['user_email'] = $postdata['pusher']['email'];
            }
            
            // Use package's own sender or the project default?
            $addressdata['sender_name'] = config('mail.from.name');
            $addressdata['sender_address'] = config('mail.from.address');
            if (config('gitdeploy.email_sender.address') !== null) {
                $addressdata['sender_name'] = config('gitdeploy.email_sender.name');
                $addressdata['sender_address'] = config('gitdeploy.email_sender.address');
            }

            // Recipients
            $addressdata['recipients'] = config('gitdeploy.email_recipients');

            // Template
            $emailTemplate = config('gitdeploy.email_template', 'gitdeploy::email');

            // Todo: Put Mail send into queue to improve performance
            \Mail::send($emailTemplate , [ 'server' => $server_response, 'git' => $postdata ], function($message) use ($postdata, $addressdata) {
                $message->from($addressdata['sender_address'], $addressdata['sender_name']);
                foreach ($addressdata['recipients'] as $recipient) {
                    $message->to($recipient['address'], $recipient['name']);
                }
                $message->subject('Repo: ' . $postdata['repository']['name'] . ' updated');
            });

        }

        return Response::json(true);
    }


    /**
     * Make sure we're comparing like for like IP address formats.
     * Since IPv6 can be supplied in short hand or long hand formats.
     *
     * e.g. ::1 is equalvent to 0000:0000:0000:0000:0000:0000:0000:0001
     * 
     * @param  string $ip   Input IP address to be formatted
     * @return string   Formatted IP address
     */
    private function formatIPAddress(string $ip) {
        return inet_ntop(inet_pton($ip));
    }

}
