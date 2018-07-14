<?php
/**
 * This file contains only the PagesController class.
 */

namespace AppBundle\Controller;

use GuzzleHttp;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Xtools\Pages;
use Xtools\PagesRepository;
use Xtools\Project;

/**
 * This controller serves the Pages tool.
 */
class PagesController extends XtoolsController
{
    const RESULTS_PER_PAGE = 1000;

    /**
     * Get the name of the tool's index route.
     * This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute()
    {
        return 'Pages';
    }

    /**
     * Display the form.
     * @Route("/pages", name="Pages")
     * @Route("/pages/", name="PagesSlash")
     * @Route("/pages/index.php", name="PagesIndexPhp")
     * @Route("/pages/{project}", name="PagesProject")
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $params = $this->parseQueryParams($request);

        // Redirect if at minimum project and username are given.
        if (isset($params['project']) && isset($params['username'])) {
            return $this->redirectToRoute('PagesResult', $params);
        }

        // Convert the given project (or default project) into a Project instance.
        $params['project'] = $this->getProjectFromQuery($params);

        // Otherwise fall through.
        return $this->render('pages/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-pages',
            'xtSubtitle' => 'tool-pages-desc',
            'xtPage' => 'pages',

            // Defaults that will get overriden if in $params.
            'namespace' => 0,
            'redirects' => 'noredirects',
            'deleted' => 'all'
        ], $params));
    }

    /**
     * Display the results.
     * @Route(
     *     "/pages/{project}/{username}/{namespace}/{redirects}/{deleted}/{offset}",
     *     name="PagesResult",
     *     requirements={
     *         "namespace" = "|all|\d+",
     *         "redirects" = "|[^/]++",
     *         "deleted" = "|all|live|deleted",
     *         "offset" = "|\d+"
     *     }
     * )
     * @param Request $request
     * @param string|int $namespace The ID of the namespace, or 'all' for all namespaces.
     * @param string $redirects One of 'noredirects', 'onlyredirects' or 'all' for both.
     * @param string $deleted One of 'live', 'deleted' or 'all' for both.
     * @param int $offset Which page of results to show, when the results are so large they are paginated.
     * @return RedirectResponse|Response
     * @codeCoverageIgnore
     */
    public function resultAction(
        Request $request,
        $namespace = '0',
        $redirects = 'noredirects',
        $deleted = 'all',
        $offset = 0
    ) {
        $ret = $this->validateProjectAndUser($request, 'Pages');
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($project, $user) = $ret;
        }

        // Check for legacy values for 'redirects', and redirect
        // back with correct values if need be. This could be refactored
        // out to XtoolsController, but this is the only tool in the suite
        // that deals with redirects, so we'll keep it confined here.
        $validRedirects = ['', 'noredirects', 'onlyredirects', 'all'];
        if ($redirects === 'none' || !in_array($redirects, $validRedirects)) {
            return $this->redirectToRoute('PagesResult', [
                'project' => $project->getDomain(),
                'username' => $user->getUsername(),
                'namespace' => $namespace,
                'redirects' => 'noredirects',
                'deleted' => $deleted,
                'offset' => $offset,
            ]);
        }

        $pagesRepo = new PagesRepository();
        $pagesRepo->setContainer($this->container);
        $pages = new Pages(
            $project,
            $user,
            $namespace,
            $redirects,
            $deleted,
            $offset
        );
        $pages->setRepository($pagesRepo);
        $pages->prepareData();

        $ret = [
            'xtPage' => 'pages',
            'xtTitle' => $user->getUsername(),
            'project' => $project,
            'user' => $user,
            'summaryColumns' => $this->getSummaryColumns($pages),
            'pages' => $pages,
            'namespace' => $namespace,
        ];

        if ($request->query->get('format') === 'PagePile') {
            return $this->getPagepileResult($project, $pages);
        }

        // Output the relevant format template.
        return $this->getFormattedResponse($request, 'pages/result', $ret);
    }

    /**
     * What columns to show in namespace totals table.
     * @param Pages $pages The Pages instance.
     * @return string[]
     * @codeCoverageIgnore
     */
    private function getSummaryColumns(Pages $pages)
    {
        $summaryColumns = ['namespace'];
        if ($pages->getDeleted() === 'deleted') {
            // Showing only deleted pages shows only the deleted column, as redirects are non-applicable.
            $summaryColumns[] = 'deleted';
        } elseif ($pages->getRedirects() == 'onlyredirects') {
            // Don't show redundant pages column if only getting data on redirects or deleted pages.
            $summaryColumns[] = 'redirects';
        } elseif ($pages->getRedirects() == 'noredirects') {
            // Don't show redundant redirects column if only getting data on non-redirects.
            $summaryColumns[] = 'pages';
        } else {
            // Order is important here.
            $summaryColumns[] = 'pages';
            $summaryColumns[] = 'redirects';
        }

        // Show deleted column only when both deleted and live pages are visible.
        if ($pages->getDeleted() === 'all') {
            $summaryColumns[] = 'deleted';
        }

        return $summaryColumns;
    }

    /**
     * Create a PagePile for the given pages, and get a Redirect to that PagePile.
     * @param Project $project
     * @param Pages $pages
     * @return RedirectResponse
     * @throws HttpException
     * @see https://tools.wmflabs.org/pagepile/
     * @codeCoverageIgnore
     */
    private function getPagepileResult(Project $project, Pages $pages)
    {
        $namespaces = $project->getNamespaces();
        $pageTitles = [];

        foreach ($pages->getResults() as $ns => $pagesData) {
            foreach ($pagesData as $page) {
                if ((int)$page['namespace'] === 0) {
                    $pageTitles[] = $page['page_title'];
                } else {
                    $pageTitles[] = $namespaces[$page['namespace']].':'.$page['page_title'];
                }
            }
        }

        $pileId = $this->createPagePile($project, $pageTitles);

        return new RedirectResponse(
            "https://tools.wmflabs.org/pagepile/api.php?id=$pileId&action=get_data&format=html&doit1"
        );
    }

    /**
     * Create a PagePile with the given titles.
     * @param Project $project
     * @param string[] $pageTitles
     * @return int The PagePile ID.
     * @throws GuzzleHttp\Exception\GuzzleException
     * @see https://tools.wmflabs.org/pagepile/
     * @codeCoverageIgnore
     */
    private function createPagePile(Project $project, $pageTitles)
    {
        $client = new GuzzleHttp\Client();
        $url = 'https://tools.wmflabs.org/pagepile/api.php';

        try {
            $res = $client->request('GET', $url, ['query' => [
                'action' => 'create_pile_with_data',
                'wiki' => $project->getDatabaseName(),
                'data' => implode("\n", $pageTitles),
            ]]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            throw new HttpException(
                414,
                'error-pagepile-too-large'
            );
        }

        $ret = json_decode($res->getBody()->getContents(), true);

        if (!isset($ret['status']) || $ret['status'] !== 'OK') {
            throw new HttpException(
                500,
                'Failed to create PagePile. There may be an issue with the PagePile API.'
            );
        }

        return $ret['pile']['id'];
    }

    /************************ API endpoints ************************/

    /**
     * Get a count of the number of pages created by a user,
     * including the number that have been deleted and are redirects.
     * @Route(
     *     "/api/user/pages_count/{project}/{username}/{namespace}/{redirects}/{deleted}",
     *     name="UserApiPagesCount",
     *     requirements={"namespace"="|\d+|all"}
     * )
     * @param Request $request
     * @param int|string $namespace The ID of the namespace of the page, or 'all' for all namespaces.
     * @param string $redirects One of 'noredirects', 'onlyredirects' or 'all' for both.
     * @param string $deleted One of 'live', 'deleted' or 'all' for both.
     * @return Response
     * @codeCoverageIgnore
     */
    public function countPagesApiAction(Request $request, $namespace = 0, $redirects = 'noredirects', $deleted = 'all')
    {
        $this->recordApiUsage('user/pages_count');

        $ret = $this->validateProjectAndUser($request);
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($project, $user) = $ret;
        }

        $pagesRepo = new PagesRepository();
        $pagesRepo->setContainer($this->container);
        $pages = new Pages(
            $project,
            $user,
            $namespace,
            $redirects,
            $deleted
        );
        $pages->setRepository($pagesRepo);

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);
        $response->setStatusCode(Response::HTTP_OK);

        $counts = $pages->getCounts();

        if ($namespace !== 'all' && isset($counts[$namespace])) {
            $counts = $counts[$namespace];
        }

        $ret = [
            'project' => $project->getDomain(),
            'username' => $user->getUsername(),
            'namespace' => $namespace,
            'redirects' => $redirects,
            'deleted' => $deleted,
            'counts' => $counts,
        ];

        $response->setData($ret);

        return $response;
    }

    /**
     * Get the pages created by by a user.
     * @Route(
     *     "/api/user/pages/{project}/{username}/{namespace}/{redirects}/{deleted}/{offset}",
     *     name="UserApiPagesCreated",
     *     requirements={"namespace"="|\d+|all"}
     * )
     * @param Request $request
     * @param int|string $namespace The ID of the namespace of the page, or 'all' for all namespaces.
     * @param string $redirects One of 'noredirects', 'onlyredirects' or 'all' for both.
     * @param string $deleted One of 'live', 'deleted' or blank for both.
     * @param int $offset Which page of results to show.
     * @return Response
     * @codeCoverageIgnore
     */
    public function getPagesApiAction(
        Request $request,
        $namespace = 0,
        $redirects = 'noredirects',
        $deleted = 'all',
        $offset = 0
    ) {
        $this->recordApiUsage('user/pages');

        // Second parameter causes it return a Redirect to the index if the user has too many edits.
        $ret = $this->validateProjectAndUser($request, 'Pages');
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($project, $user) = $ret;
        }

        $pagesRepo = new PagesRepository();
        $pagesRepo->setContainer($this->container);
        $pages = new Pages(
            $project,
            $user,
            $namespace,
            $redirects,
            $deleted,
            $offset
        );
        $pages->setRepository($pagesRepo);

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);
        $response->setStatusCode(Response::HTTP_OK);

        $pagesList = $pages->getResults();

        if ($namespace !== 'all' && isset($pagesList[$namespace])) {
            $pagesList = $pagesList[$namespace];
        }

        $ret = [
            'project' => $project->getDomain(),
            'username' => $user->getUsername(),
            'namespace' => $namespace,
            'redirects' => $redirects,
            'deleted' => $deleted
        ];

        if ($pages->getNumResults() === $pages->resultsPerPage()) {
            $ret['continue'] = $offset + 1;
        }

        $ret['pages'] = $pagesList;

        $response->setData($ret);

        return $response;
    }
}
