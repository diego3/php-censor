<?php

namespace PHPCensor\Controller;

use b8;
use b8\Store;
use Exception;
use PHPCensor\Helper\Lang;
use PHPCensor\Model\Project;
use PHPCensor\Service\BuildService;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\ProjectStore;
use b8\Controller;
use b8\Config;
use b8\HttpClient;
use b8\Exception\HttpException\NotFoundException;

/**
 * Webhook Controller - Processes webhook pings from BitBucket, Github, Gitlab, Gogs, etc.
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Sami Tikka <stikka@iki.fi>
 * @author Alex Russell <alex@clevercherry.com>
 * @author Guillaume Perréal <adirelle@gmail.com>
 *
 */
class WebhookController extends Controller
{
    /**
     * @var BuildStore
     */
    protected $buildStore;

    /**
     * @var ProjectStore
     */
    protected $projectStore;

    /**
     * @var BuildService
     */
    protected $buildService;

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init()
    {
        $this->buildStore = Store\Factory::getStore('Build');
        $this->projectStore = Store\Factory::getStore('Project');
        $this->buildService = new BuildService($this->buildStore);
    }

    /** Handle the action, Ensuring to return a JsonResponse.
     *
     * @param string $action
     * @param mixed $actionParams
     *
     * @return \b8\Http\Response
     */
    public function handleAction($action, $actionParams)
    {
        $response = new b8\Http\Response\JsonResponse();
        try {
            $data = parent::handleAction($action, $actionParams);
            if (isset($data['responseCode'])) {
                $response->setResponseCode($data['responseCode']);
                unset($data['responseCode']);
            }
            $response->setContent($data);
        } catch (Exception $ex) {
            $response->setResponseCode(500);
            $response->setContent(['status' => 'failed', 'error' => $ex->getMessage()]);
        }
        return $response;
    }

    /**
     * Called by Bitbucket.
     */
    public function bitbucket($projectId)
    {
        $project = $this->fetchProject($projectId, ['bitbucket', 'remote']);

        // Support both old services and new webhooks
        if ($payload = $this->getParam('payload')) {
            return $this->bitbucketService(json_decode($payload, true), $project);
        }

        $payload = json_decode(file_get_contents("php://input"), true);

        if (empty($payload['push']['changes'])) {
            // Invalid event from bitbucket
            return [
                'status' => 'failed',
                'commits' => []
            ];
        }

        return $this->bitbucketWebhook($payload, $project);
    }

    /**
     * Bitbucket webhooks.
     */
    protected function bitbucketWebhook($payload, $project)
    {
        $results = [];
        $status  = 'failed';
        foreach ($payload['push']['changes'] as $commit) {
            try {
                $email = $commit['new']['target']['author']['raw'];
                $email = substr($email, 0, strpos($email, '>'));
                $email = substr($email, strpos($email, '<') + 1);

                $results[$commit['new']['target']['hash']] = $this->createBuild(
                    $project,
                    $commit['new']['target']['hash'],
                    $commit['new']['name'],
                    $email,
                    $commit['new']['target']['message']
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$commit['new']['target']['hash']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Bitbucket POST service.
     */
    protected function bitbucketService($payload, $project)
    {
        $payload = json_decode($this->getParam('payload'), true);

        $results = [];
        $status  = 'failed';
        foreach ($payload['commits'] as $commit) {
            try {
                $email = $commit['raw_author'];
                $email = substr($email, 0, strpos($email, '>'));
                $email = substr($email, strpos($email, '<') + 1);

                $results[$commit['raw_node']] = $this->createBuild(
                    $project,
                    $commit['raw_node'],
                    $commit['branch'],
                    $email,
                    $commit['message']
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$commit['raw_node']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Called by POSTing to /webhook/git/<project_id>?branch=<branch>&commit=<commit>
     *
     * @param string $projectId
     * 
     * @return array
     */
    public function git($projectId)
    {
        $project = $this->fetchProject($projectId, ['local', 'remote']);
        $branch = $this->getParam('branch', $project->getBranch());
        $commit = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer = $this->getParam('committer');

        return $this->createBuild($project, $commit, $branch, $committer, $commitMessage);
    }

    /**
     * Called by Github Webhooks:
     */
    public function github($projectId)
    {
        $project = $this->fetchProject($projectId, ['github', 'remote']);

        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $payload = json_decode(file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                $payload = json_decode($this->getParam('payload'), true);
                break;
            default:
                return ['status' => 'failed', 'error' => 'Content type not supported.', 'responseCode' => 401];
        }

        // Handle Pull Request web hooks:
        if (array_key_exists('pull_request', $payload)) {
            return $this->githubPullRequest($project, $payload);
        }

        // Handle Push web hooks:
        if (array_key_exists('commits', $payload)) {
            return $this->githubCommitRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a commit webhook.
     *
     * @param Project $project
     * @param array $payload
     *
     * @return array
     */
    protected function githubCommitRequest(Project $project, array $payload)
    {
        // Github sends a payload when you close a pull request with a
        // non-existent commit. We don't want this.
        if (array_key_exists('after', $payload) && $payload['after'] === '0000000000000000000000000000000000000000') {
            return ['status' => 'ignored'];
        }

        if (isset($payload['commits']) && is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = [];
            $status  = 'failed';
            foreach ($payload['commits'] as $commit) {
                if (!$commit['distinct']) {
                    $results[$commit['id']] = ['status' => 'ignored'];
                    continue;
                }

                try {
                    $branch = str_replace('refs/heads/', '', $payload['ref']);
                    $committer = $commit['committer']['email'];
                    $results[$commit['id']] = $this->createBuild(
                        $project,
                        $commit['id'],
                        $branch,
                        $committer,
                        $commit['message']
                    );
                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }
            return ['status' => $status, 'commits' => $results];
        }

        if (substr($payload['ref'], 0, 10) == 'refs/tags/') {
            // If we don't, but we're dealing with a tag, add that instead:
            $branch = str_replace('refs/tags/', 'Tag: ', $payload['ref']);
            $committer = $payload['pusher']['email'];
            $message = $payload['head_commit']['message'];
            return $this->createBuild($project, $payload['after'], $branch, $committer, $message);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a Pull Request webhook.
     * 
     * @param Project $project
     * @param array   $payload
     * 
     * @return array
     * 
     * @throws Exception
     */
    protected function githubPullRequest(Project $project, array $payload)
    {
        // We only want to know about open pull requests:
        if (!in_array($payload['action'], ['opened', 'synchronize', 'reopened'])) {
            return ['status' => 'ok'];
        }

        $headers = [];
        $token   = Config::getInstance()->get('php-censor.github.token');

        if (!empty($token)) {
            $headers[] = 'Authorization: token ' . $token;
        }

        $url    = $payload['pull_request']['commits_url'];
        $http   = new HttpClient();
        $http->setHeaders($headers);

        //for large pull requests, allow grabbing more then the default number of commits
        $custom_per_page = Config::getInstance()->get('php-censor.github.per_page');
        $params          = [];
        if ($custom_per_page) {
            $params["per_page"] = $custom_per_page;
        }
        $response = $http->get($url, $params);

        // Check we got a success response:
        if (!$response['success']) {
            throw new Exception('Could not get commits, failed API request.');
        }

        $results = [];
        $status  = 'failed';
        foreach ($response['body'] as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['sha'];
            if ($id != $payload['pull_request']['head']['sha']) {
                $results[$id] = ['status' => 'ignored', 'message' => 'not branch head'];
                continue;
            }

            try {
                $branch    = str_replace('refs/heads/', '', $payload['pull_request']['base']['ref']);
                $committer = $commit['commit']['author']['email'];
                $message   = $commit['commit']['message'];

                $remoteUrlKey = $payload['pull_request']['head']['repo']['private'] ? 'ssh_url' : 'clone_url';

                $extra = [
                    'build_type'          => 'pull_request',
                    'pull_request_id'     => $payload['pull_request']['id'],
                    'pull_request_number' => $payload['number'],
                    'remote_branch'       => $payload['pull_request']['head']['ref'],
                    'remote_url'          => $payload['pull_request']['head']['repo'][$remoteUrlKey],
                ];

                $results[$id] = $this->createBuild($project, $id, $branch, $committer, $message, $extra);
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Called by Gitlab Webhooks:
     */
    public function gitlab($projectId)
    {
        $project = $this->fetchProject($projectId, ['gitlab', 'remote']);

        $payloadString = file_get_contents("php://input");
        $payload = json_decode($payloadString, true);

        // build on merge request events
        if (isset($payload['object_kind']) && $payload['object_kind'] == 'merge_request') {
            $attributes = $payload['object_attributes'];
            if ($attributes['state'] == 'opened' || $attributes['state'] == 'reopened') {
                $branch = $attributes['source_branch'];
                $commit = $attributes['last_commit'];
                $committer = $commit['author']['email'];

                return $this->createBuild($project, $commit['id'], $branch, $committer, $commit['message']);
            }
        }

        // build on push events
        if (isset($payload['commits']) && is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = [];
            $status  = 'failed';
            foreach ($payload['commits'] as $commit) {
                try {
                    $branch = str_replace('refs/heads/', '', $payload['ref']);
                    $committer = $commit['author']['email'];
                    $results[$commit['id']] = $this->createBuild(
                        $project,
                        $commit['id'],
                        $branch,
                        $committer,
                        $commit['message']
                    );
                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }
            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }


    /**
     * Called by POSTing to /webhook/svn/<project_id>?branch=<branch>&commit=<commit>
     *
     * @author Sylvain Lévesque <slevesque@gezere.com>
     * 
     * @param string $projectId
     * 
     * @return array
     */
    public function svn($projectId)
    {
        $project = $this->fetchProject($projectId, 'svn');
        $branch = $this->getParam('branch', $project->getBranch());
        $commit = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer = $this->getParam('committer');

        return $this->createBuild($project, $commit, $branch, $committer, $commitMessage);
    }

    /**
     * Called by Gogs Webhooks:
     * 
     * @param string $projectId
     * 
     * @return array
     */
    public function gogs($projectId)
    {
        $project = $this->fetchProject($projectId, ['gogs', 'remote']);
        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $payload = json_decode(file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                $payload = json_decode($this->getParam('payload'), true);
                break;
            default:
                return ['status' => 'failed', 'error' => 'Content type not supported.', 'responseCode' => 401];
        }

        // Handle Push web hooks:
        if (array_key_exists('commits', $payload)) {
            return $this->gogsCommitRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Gogs sends a commit webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     */
    protected function gogsCommitRequest(Project $project, array $payload)
    {
        if (isset($payload['commits']) && is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:
            $results = [];
            $status  = 'failed';
            foreach ($payload['commits'] as $commit) {
                try {
                    $branch = str_replace('refs/heads/', '', $payload['ref']);
                    $committer = $commit['author']['email'];
                    $results[$commit['id']] = $this->createBuild(
                        $project,
                        $commit['id'],
                        $branch,
                        $committer,
                        $commit['message']
                    );
                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Wrapper for creating a new build.
     *
     * @param Project $project
     * @param string $commitId
     * @param string $branch
     * @param string $committer
     * @param string $commitMessage
     * @param array $extra
     *
     * @return array
     *
     * @throws Exception
     */
    protected function createBuild(
        Project $project,
        $commitId,
        $branch,
        $committer,
        $commitMessage,
        array $extra = null
    ) {
        if ($project->getArchived()) {
            throw new NotFoundException(Lang::get('project_x_not_found', $project->getId()));
        }

        // Check if a build already exists for this commit ID:
        $builds = $this->buildStore->getByProjectAndCommit($project->getId(), $commitId);

        if ($builds['count']) {
            return [
                'status'  => 'ignored',
                'message' => sprintf('Duplicate of build #%d', $builds['items'][0]->getId())
            ];
        }

        // If not, create a new build job for it:
        $build = $this->buildService->createBuild($project, $commitId, $branch, $committer, $commitMessage, $extra);

        return ['status' => 'ok', 'buildID' => $build->getID()];
    }

    /**
     * Fetch a project and check its type.
     *
     * @param int $projectId
     * @param array|string $expectedType
     *
     * @return Project
     *
     * @throws Exception If the project does not exist or is not of the expected type.
     */
    protected function fetchProject($projectId, $expectedType)
    {
        $project = $this->projectStore->getById($projectId);

        if (empty($projectId)) {
            throw new Exception('Project does not exist: ' . $projectId);
        }

        if (is_array($expectedType)
            ? !in_array($project->getType(), $expectedType)
            : $project->getType() !== $expectedType
        ) {
            throw new Exception('Wrong project type: ' . $project->getType());
        }

        return $project;
    }
}
